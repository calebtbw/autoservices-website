<?php
require 'iconm3/db.php';
require 'iconm3/config.php';
session_start();

// Prevent crawlers for booking-related actions
header('X-Robots-Tag: noindex, nofollow');

$successMessage = '';
$errorMessage = '';
$bookingDetails = null;

// Check if car detailing service is cut off
$cutOffStmt = $pdo->query("SELECT value FROM settings WHERE name = 'car_detailing_cutoff'");
$cutOff = $cutOffStmt->fetchColumn() == '1';

// Log after checking cutoff
// error_log("Cutoff checked: " . ($cutOff ? 'true' : 'false'), 3, '/iconm3/logs/debug.log');

// Fetch slots for the next 3 months to preload
$currentMonth = date('Y-m');
$endMonth = date('Y-m', strtotime('+2 months'));
$startDate = "$currentMonth-01";
$endDate = date('Y-m-t', strtotime($endMonth));

$stmt = $pdo->prepare("SELECT * FROM slots WHERE service_type = 'car_detailing' AND slot_date BETWEEN ? AND ?");
$stmt->execute([$startDate, $endDate]);
$allSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Log after fetching slots
// error_log("Slots fetched: " . count($allSlots), 3, '/iconm3/logs/debug.log');

// Determine the base URL dynamically
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = "$protocol://$host/icon-staging";

// Log base URL
// error_log("Base URL: $baseUrl", 3, '/iconm3/logs/debug.log');

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm-booking'])) {
    // error_log("Booking submission started", 3, '/iconm3/logs/debug.log');

    try {
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING));
        $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
        $phone = trim(filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING));
        $service_type = filter_input(INPUT_POST, 'service_type', FILTER_SANITIZE_STRING);
        $slot_id = filter_input(INPUT_POST, 'slot_id', FILTER_SANITIZE_NUMBER_INT);
        $payment_method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_STRING);
        $total_amount = filter_input(INPUT_POST, 'total_amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

        // error_log("Form data: name=$name, email=$email, phone=$phone, service_type=$service_type, slot_id=$slot_id, payment_method=$payment_method, total_amount=$total_amount", 3, '/iconm3/logs/debug.log');

        if (!$name || !$email || !$phone || !$service_type || !$slot_id || !$payment_method || !$total_amount) {
            $errorMessage = 'All fields are required.';
            // error_log("Error: All fields are required", 3, '/iconm3/logs/debug.log');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Invalid email address.';
            // error_log("Error: Invalid email address", 3, '/iconm3/logs/debug.log');
        } elseif (!in_array($payment_method, ['card', 'paynow'])) {
            $errorMessage = 'Invalid payment method.';
            // error_log("Error: Invalid payment method", 3, '/iconm3/logs/debug.log');
        } else {
            // Check if slot is still available and fetch slot details
            $slotCheckStmt = $pdo->prepare("SELECT is_booked, slot_date, slot_time FROM slots WHERE id = ? AND service_type = 'car_detailing'");
            $slotCheckStmt->execute([$slot_id]);
            $slot = $slotCheckStmt->fetch(PDO::FETCH_ASSOC);

            // error_log("Slot check result: " . ($slot ? 'found' : 'not found'), 3, '/iconm3/logs/debug.log');

            if (!$slot || $slot['is_booked'] == 1) {
                $errorMessage = 'Selected slot is no longer available.';
                // error_log("Error: Selected slot is no longer available", 3, '/iconm3/logs/debug.log');
            } elseif ($cutOff) {
                $errorMessage = 'Car detailing service is currently unavailable.';
                // error_log("Error: Car detailing service is currently unavailable", 3, '/iconm3/logs/debug.log');
            } else {
                // Begin transaction
                $pdo->beginTransaction();

                // Insert client
                $clientStmt = $pdo->prepare("INSERT INTO clients (name, email, phone, service) VALUES (?, ?, ?, ?)");
                $clientStmt->execute([$name, $email, $phone, $service_type]);
                $client_id = $pdo->lastInsertId();

                // error_log("Client inserted: ID $client_id", 3, '/iconm3/logs/debug.log');

                // Mark slot as booked
                $updateSlotStmt = $pdo->prepare("UPDATE slots SET is_booked = 1 WHERE id = ?");
                $updateSlotStmt->execute([$slot_id]);

                // error_log("Slot marked as booked: ID $slot_id", 3, '/iconm3/logs/debug.log');

                // Use slot_date and slot_time for pickup_date and pickup_time
                $pickup_date = $slot['slot_date'];
                $pickup_time = $slot['slot_time'];
                $booking_date_display = $pickup_date . ' ' . $pickup_time;

                // error_log("Booking date for display: $booking_date_display", 3, '/iconm3/logs/debug.log');

                // Insert booking
                $bookingStmt = $pdo->prepare("
                    INSERT INTO bookings (
                        client_id, service_type, service_id, slot_id, total_amount, 
                        pickup_date, pickup_time, pickup_location, dropoff_location, 
                        payment_method, payment_status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $payment_status = 'pending';
                $pickup_location = '';
                $dropoff_location = '';
                $bookingStmt->execute([
                    $client_id, $service_type, $slot_id, $slot_id, $total_amount,
                    $pickup_date, $pickup_time, $pickup_location, $dropoff_location,
                    $payment_method, $payment_status
                ]);
                $booking_id = $pdo->lastInsertId();

                // error_log("Booking inserted: ID $booking_id", 3, '/iconm3/logs/debug.log');

                // Create HitPay Payment Request
                $paymentData = [
                    'name' => ucfirst($service_type) . ' Service',
                    'email' => $email,
                    'phone' => $phone,
                    'amount' => number_format($total_amount, 2, '.', ''),
                    'currency' => 'SGD',
                    'redirect_url' => "$baseUrl/car-detailing.php?booking_id=$booking_id",
                    'webhook' => "$baseUrl/iconm3/hitpay_webhook.php",
                    'reference_number' => (string)$booking_id,
                    'description' => "Date & Time: $booking_date_display",
                    'payment_methods' => [$payment_method === 'paynow' ? 'paynow_online' : 'card'],
                    'send_email' => 'true',
                ];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, HITPAY_PAYMENT_API_URL);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($paymentData));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'X-BUSINESS-API-KEY: ' . HITPAY_API_KEY,
                    'X-REQUESTED-WITH: XMLHttpRequest'
                ]);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);

                $response = curl_exec($ch);
                if (curl_errno($ch)) {
                    $curlError = curl_error($ch);
                    curl_close($ch);
                    throw new Exception('Payment gateway connection error: ' . $curlError . '. Please try again later or contact support.');
                }
                curl_close($ch);

                $responseData = json_decode($response, true);
                if (!$responseData || !isset($responseData['url'])) {
                    throw new Exception('Failed to create payment request: ' . ($responseData['message'] ?? 'Unknown error') . '. Please try again or contact support.');
                }

                // error_log("HitPay payment request created: " . $response, 3, '/iconm3/logs/debug.log');

                // Update booking with payment_request_id
                $stmt = $pdo->prepare("UPDATE bookings SET payment_request_id = ? WHERE id = ?");
                $stmt->execute([$responseData['id'], $booking_id]);

                // Commit transaction
                $pdo->commit();

                // error_log("Transaction committed", 3, '/iconm3/logs/debug.log');

                // Store payment request ID in session for verification
                $_SESSION['hitpay_payment_request_id'] = $responseData['id'];

                // Redirect to HitPay payment page
                header('Location: ' . $responseData['url']);
                exit;
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorMessage = "Error: " . $e->getMessage();
        // error_log("Booking error: " . $e->getMessage(), 3, '/iconm3/logs/php_errors.log');
    }
}

// Handle success redirection from HitPay
if (isset($_GET['booking_id'])) {
    // error_log("Handling success redirect: booking_id=" . $_GET['booking_id'], 3, '/iconm3/logs/debug.log');

    try {
        $booking_id = filter_input(INPUT_GET, 'booking_id', FILTER_SANITIZE_NUMBER_INT);
        if ($booking_id) {
            $stmt = $pdo->prepare("
                SELECT b.*, c.name, c.email, c.phone
                FROM bookings b
                JOIN clients c ON b.client_id = c.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $bookingDetails = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($bookingDetails) {
                if ($bookingDetails['payment_status'] === 'completed') {
                    $successMessage = 'Payment successful! Your booking is confirmed.';
                } else {
                    $successMessage = 'Booking created. Awaiting payment confirmation.';
                }
            } else {
                $errorMessage = 'Booking not found.';
            }
        }
    } catch (PDOException $e) {
        $errorMessage = "Database error: " . $e->getMessage();
        // error_log("Booking success error: " . $e->getMessage(), 3, '/iconm3/logs/php_errors.log');
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Required Meta Tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>Car Detailing - Icon Services</title>
    <!-- SEO -->
    <meta name="description" content="We Provide Premium Car Detailing, Car Rental, Car Servicing, Valet, and Limousine Services.">
    <meta name="keywords" content="singapore car detailing, singapore car rental, singapore car servicing, singapore valet service, singapore limousine service">
    <meta name="author" content="Icon Services">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://iconcarrentalsg.com/car-detailing">
    <meta property="og:site_name" content="Icon Services">
    <meta property="og:description" content="We Provide Premium Car Detailing, Car Rental, Car Servicing, Valet, and Limousine Services.">
    <meta property="og:keywords" content="singapore car detailing, singapore car rental, singapore car servicing, singapore valet service, singapore limousine service">
    <!-- GSC Code -->
    <meta name="google-site-verification" content="CODEHERE">
    <!-- BWT Code -->
    <meta name="msvalidate.01" content="CODEHERE">
    <!-- Canonicalization -->
    <link rel="canonical" href="https://iconcarrentalsg.com/car-detailing">
    <!-- Schema -->
    <script type="application/ld+json">
      {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "Icon Services",
        "alternateName": "Icon Services",
        "url": "URL",
        "description": "We Provide Premium Car Detailing, Car Rental, Car Servicing, Valet, and Limousine Services.",
        "logo": "https://iconcarrentalsg.com/img/icon.png",
        "sameAs": [
          "https://www.facebook.com/HERE/",
          "https://tiktok.com/HERE/",
          "https://www.instagram.com/HERE/"
        ]
      }
    </script>
    <!-- Global site tag (gtag.js) - Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=IDHERE"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag() {
        dataLayer.push(arguments);
      }
      gtag("js", new Date());
      gtag("config", "IDHERE");
    </script>
    <!-- Favicon -->
    <link rel="icon" href="./img/favicon.ico" type="image/x-icon">
    <!-- Stylesheets -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="./css/styles.css">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-xl navbar-light bg-white fixed-top">
        <div class="container">
            <a class="navbar-brand" href="./">
                <img src="./img/icon-detailing-service.png" alt="Icon Services Logo" class="logo">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link active" href="./">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="./car-detailing">Car Detailing</a></li>
                    <li class="nav-item"><a class="nav-link" href="./car-rental">Car Rental</a></li>
                    <li class="nav-item"><a class="nav-link" href="./servicing-and-valet">Servicing & Valet</a></li>
                    <li class="nav-item"><a class="nav-link" href="./limousine-service">Limousine</a></li>
                    <li class="nav-item"><a class="nav-link" href="./contact-us">Contact Us</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <section class="py-5 bg-light" style="padding-top: 8rem !important;">
        <div class="container">
            <h2 class="text-center fw-bold mb-5 animate__animated animate__fadeIn">Car Detailing</h2>
            <p class="lead text-center mb-5">Transform your vehicle with our premium detailing services, crafted for perfection.</p>

            <?php if ($errorMessage): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($successMessage): ?>
                <div class="alert alert-success" role="alert">
                    <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($bookingDetails): ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">Booking Details</h5>
                        <p><strong>Booking ID:</strong> <?php echo htmlspecialchars($bookingDetails['id']); ?></p>
                        <p><strong>Client Name:</strong> <?php echo htmlspecialchars($bookingDetails['name']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($bookingDetails['email']); ?></p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($bookingDetails['phone']); ?></p>
                        <p><strong>Service Type:</strong> <?php echo htmlspecialchars($bookingDetails['service_type']); ?></p>
                        <p><strong>Slot ID:</strong> <?php echo htmlspecialchars($bookingDetails['slot_id']); ?></p>
                        <p><strong>Date & Time:</strong> <?php echo htmlspecialchars($bookingDetails['pickup_date'] . ' ' . $bookingDetails['pickup_time']); ?></p>
                        <p><strong>Total Amount:</strong> S$<?php echo number_format($bookingDetails['total_amount'], 2); ?></p>
                        <p><strong>Payment Method:</strong> <?php echo htmlspecialchars($bookingDetails['payment_method']); ?></p>
                        <p><strong>Payment Status:</strong> <?php echo htmlspecialchars($bookingDetails['payment_status']); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card border-light shadow-sm h-100">
                        <div class="card-body text-center">
                            <h3 class="card-title fw-bold mb-3">Basic Wash</h3>
                            <p class="card-text mb-4">Exterior wash, tire cleaning, and window cleaning.</p>
                            <p class="fw-bold text-primary">S$49.99</p>
                            <button class="btn btn-primary w-100 book-service" data-service="Basic Wash" data-price="49.99" data-bs-toggle="modal" data-bs-target="#bookingModal" <?php echo $cutOff ? 'disabled' : ''; ?>>Book Now</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-light shadow-sm h-100">
                        <div class="card-body text-center">
                            <h3 class="card-title fw-bold mb-3">Premium Detail</h3>
                            <p class="card-text mb-4">Full exterior and interior detailing with wax and polish.</p>
                            <p class="fw-bold text-primary">S$149.99</p>
                            <button class="btn btn-primary w-100 book-service" data-service="Premium Detail" data-price="149.99" data-bs-toggle="modal" data-bs-target="#bookingModal" <?php echo $cutOff ? 'disabled' : ''; ?>>Book Now</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-light shadow-sm h-100">
                        <div class="card-body text-center">
                            <h3 class="card-title fw-bold mb-3">Ceramic Coating</h3>
                            <p class="card-text mb-4">Professional ceramic coating for long-lasting protection.</p>
                            <p class="fw-bold text-primary">S$299.99</p>
                            <button class="btn btn-primary w-100 book-service" data-service="Ceramic Coating" data-price="299.99" data-bs-toggle="modal" data-bs-target="#bookingModal" <?php echo $cutOff ? 'disabled' : ''; ?>>Book Now</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($cutOff): ?>
                <p class="text-center text-danger mt-4">Car detailing bookings are currently closed.</p>
            <?php endif; ?>
        </div>
    </section>

    <div class="modal fade" id="bookingModal" tabindex="-1" aria-labelledby="bookingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bookingModalLabel">Schedule Your Detailing</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h4 id="selected-service" class="text-center mb-4"></h4>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="date-picker" class="form-label">Select Date</label>
                            <input type="date" id="date-picker" class="form-control" min="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d', strtotime($endDate)); ?>">
                        </div>
                    </div>
                    <div id="time-slots" class="mb-3"></div>
                    <form id="booking-form" class="d-none" method="POST">
                        <input type="hidden" name="service_type" value="car_detailing">
                        <input type="hidden" name="slot_id">
                        <input type="hidden" name="total_amount">
                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="phone" name="phone" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <div>
                                <input type="radio" id="card" name="payment_method" value="card" required>
                                <label for="card">Credit/Debit Card</label>
                            </div>
                            <div>
                                <input type="radio" id="paynow" name="payment_method" value="paynow">
                                <label for="paynow">PayNow</label>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" name="confirm-booking">Confirm Booking</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <section class="reviews-section">
        <div class="container">
            <h2 class="text-center fw-bold mb-5 animate__animated animate__fadeIn">What Our Customers Say</h2>
            <div class="row">
                <div class="col-md-4">
                    <div class="review-card">
                        <div class="stars">★★★★★</div>
                        <p class="review-text">"Amazing car detailing service! My car looks brand new."</p>
                        <p class="reviewer-name">Sarah L.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="review-card">
                        <div class="stars">★★★★★</div>
                        <p class="review-text">"The limousine service was perfect for our wedding day."</p>
                        <p class="reviewer-name">James R.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="review-card">
                        <div class="stars">★★★★☆</div>
                        <p class="review-text">"Great rental experience, the car was clean and reliable."</p>
                        <p class="reviewer-name">Emily T.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="faq-section" style="padding-top: 2rem; padding-bottom: 3rem;">
        <div class="container">
            <h2 class="text-center fw-bold mb-4">Frequently Asked Questions</h2>
            <div class="accordion" id="faqAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header" id="faqHeading1">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse1" aria-expanded="true" aria-controls="faqCollapse1">
                            What does your car detailing service include?
                        </button>
                    </h2>
                    <div id="faqCollapse1" class="accordion-collapse collapse show" aria-labelledby="faqHeading1" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Our car detailing service includes a thorough cleaning of both the interior and exterior of your vehicle, waxing, polishing, and vacuuming to ensure a pristine finish.
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="faqHeading2">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse2" aria-expanded="false" aria-controls="faqCollapse2">
                            How long does a car detailing session take?
                        </button>
                    </h2>
                    <div id="faqCollapse2" class="accordion-collapse collapse" aria-labelledby="faqHeading2" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            A standard car detailing session typically takes 2-3 hours, depending on the size and condition of the vehicle.
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="faqHeading3">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse3" aria-expanded="false" aria-controls="faqCollapse3">
                            Can I book a detailing service online?
                        </button>
                    </h2>
                    <div id="faqCollapse3" class="accordion-collapse collapse" aria-labelledby="faqHeading3" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Yes, you can easily book a detailing service online by selecting your preferred slot and confirming your booking through our website.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="cta-section">
        <div class="container">
            <h3 class="animate__animated animate__fadeIn">Ready to Experience Premium Automotive Services?</h3>
            <p>Reach out to us for any inquiries or to book your next service.</p>
            <a href="./contact-us" class="btn btn-info btn-lg animate__animated animate__fadeIn">Contact Us</a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer-section">
        <div class="container">
            <div class="row">
                <!-- Logo and Company Info -->
                <div class="col-md-4 mb-4">
                    <a href="./">
                        <img src="./img/icon.png" alt="Icon Services Logo" class="footer-logo mb-3" style="max-width: 150px; max-height: 150px;">
                    </a>
                    <p class="text-white">Icon Services Pte. Ltd.<br>
                    UEN: XXYYZZ123</p>
                </div>
                <!-- Quick Links -->
                <div class="col-md-4 mb-4">
                    <h5 class="text-white fw-bold mb-3">Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="./" class="footer-link">Home</a></li>
                        <li><a href="./car-detailing" class="footer-link">Car Detailing</a></li>
                        <li><a href="./car-rental" class="footer-link">Car Rental</a></li>
                        <li><a href="./servicing-and-valet" class="footer-link">Servicing & Valet</a></li>
                        <li><a href="./limousine-service" class="footer-link">Limousine</a></li>
                        <li><a href="./contact-us" class="footer-link">Contact Us</a></li>
                    </ul>
                </div>
                <!-- Social Links -->
                <div class="col-md-4 mb-4">
                    <h5 class="text-white fw-bold mb-3">Follow Us</h5>
                    <div class="social-links mb-3">
                        <a href="https://www.instagram.com/yourprofile" target="_blank" class="text-white me-3" title="Instagram">
                            <i class="bi bi-instagram" style="font-size: 1.5rem;"></i>
                        </a>
                        <a href="https://www.facebook.com/yourprofile" target="_blank" class="text-white me-3" title="Facebook">
                            <i class="bi bi-facebook" style="font-size: 1.5rem;"></i>
                        </a>
                        <a href="https://www.tiktok.com/@yourprofile" target="_blank" class="text-white" title="TikTok">
                            <i class="bi bi-tiktok" style="font-size: 1.5rem;"></i>
                        </a>
                    </div>
                    <p class="text-white" style="font-size: x-small;">Developed by <a href="https://nxstudios.sg" class="footer-link">NXStudios</a>a part of NXGroup.</p>
                </div>
            </div>
            <div class="text-center text-white mt-4 pt-3 border-top">
                <p class="mb-0">Copyright © 2025 Icon Services Pte. Ltd. All Rights Reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Chatbox Widget -->
    <div class="chatbox-widget">
        <div class="chatbox-toggle">
            <i class="bi bi-chat-dots-fill"></i>
        </div>
        <div class="chatbox-content">
            <h5 class="chatbox-title">Chat with Us</h5>
            <a href="https://wa.me/6598765432?text=Hello,%20I%20need%20assistance!" target="_blank" class="chatbox-link">
                <img src="https://cdn-icons-png.flaticon.com/128/3670/3670051.png" alt="WhatsApp" class="chatbox-icon">
                WhatsApp
            </a>
            <a href="https://line.me/R/ti/p/@your-line-id" target="_blank" class="chatbox-link">
                <img src="https://cdn-icons-png.flaticon.com/128/3670/3670089.png" alt="LINE" class="chatbox-icon">
                LINE
            </a>
            <a href="weixin://dl/chat?your-wechat-id" target="_blank" class="chatbox-link">
                <img src="https://cdn-icons-png.flaticon.com/128/3670/3670101.png" alt="WeChat" class="chatbox-icon">
                WeChat
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="./js/script.js"></script>
    <script>
        let selectedDate = null;
        let selectedSlotId = null;
        let totalAmount = 0;
        const allSlots = <?php echo json_encode($allSlots); ?>;

        document.querySelectorAll('.book-service').forEach(button => {
            button.addEventListener('click', () => {
                const service = button.getAttribute('data-service');
                totalAmount = parseFloat(button.getAttribute('data-price'));
                document.getElementById('selected-service').textContent = `Booking: ${service}`;
                document.querySelector('input[name="total_amount"]').value = totalAmount;
                document.getElementById('date-picker').value = '';
                document.getElementById('time-slots').innerHTML = '';
                document.getElementById('booking-form').classList.add('d-none');
            });
        });

        document.getElementById('date-picker').addEventListener('change', function() {
            selectedDate = this.value;
            loadTimeSlots();
        });

        function loadTimeSlots() {
            const timeSlotsDiv = document.getElementById('time-slots');
            timeSlotsDiv.innerHTML = '<h5>Select a Time Slot</h5>';
            if (!selectedDate) {
                timeSlotsDiv.innerHTML += '<p>Please select a date.</p>';
                return;
            }

            const daySlots = allSlots.filter(slot => slot.slot_date === selectedDate);
            const availableSlots = daySlots.filter(slot => !slot.is_booked);
            if (availableSlots.length === 0) {
                timeSlotsDiv.innerHTML += '<p>No available slots for this day.</p>';
                return;
            }

            const slotsList = document.createElement('div');
            slotsList.className = 'd-flex flex-wrap gap-2';
            availableSlots.forEach(slot => {
                const button = document.createElement('button');
                button.className = 'btn btn-outline-primary';
                button.textContent = slot.slot_time.slice(0, 5);
                button.addEventListener('click', () => {
                    document.querySelectorAll('#time-slots button').forEach(btn => btn.classList.remove('active'));
                    button.classList.add('active');
                    selectedSlotId = slot.id;
                    document.getElementById('booking-form').classList.remove('d-none');
                    document.querySelector('input[name="slot_id"]').value = selectedSlotId;
                });
                slotsList.appendChild(button);
            });
            timeSlotsDiv.appendChild(slotsList);
        }

        document.getElementById('bookingModal').addEventListener('hidden.bs.modal', () => {
            document.getElementById('selected-service').textContent = '';
            document.getElementById('date-picker').value = '';
            document.getElementById('time-slots').innerHTML = '';
            document.getElementById('booking-form').classList.add('d-none');
            selectedDate = null;
            selectedSlotId = null;
        });

        // Chatbox toggle functionality
        document.querySelector('.chatbox-toggle').addEventListener('click', function () {
            const content = document.querySelector('.chatbox-content');
            content.style.display = content.style.display === 'block' ? 'none' : 'block';
        });
    </script>
</body>
</html>