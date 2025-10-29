<?php
require 'iconm3/db.php';
require 'iconm3/config.php';
session_start();

// Prevent crawlers for booking-related actions
header('X-Robots-Tag: noindex, nofollow');

$successMessage = '';
$errorMessage = '';
$bookingDetails = null;

// Fetch available limousines
$stmt = $pdo->query("SELECT * FROM limousines WHERE availability = 1");
$limousines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if limousine service is cut off
$cutOffStmt = $pdo->query("SELECT value FROM settings WHERE name = 'limousine_cutoff'");
$cutOff = $cutOffStmt->fetchColumn() == '1';

// Generate available times (every 30 minutes from 8:00 AM to 10:00 PM)
$availableTimes = [];
$startTime = new DateTime('08:00');
$endTime = new DateTime('22:00');
$interval = new DateInterval('PT30M');
while ($startTime <= $endTime) {
    $availableTimes[] = $startTime->format('H:i');
    $startTime->add($interval);
}

// Determine the base URL dynamically
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = "$protocol://$host/icon-staging";

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm-booking'])) {
    try {
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING));
        $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
        $phone = trim(filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING));
        $service_type = trim(filter_input(INPUT_POST, 'service_type', FILTER_SANITIZE_STRING));
        $pickup_date = trim(filter_input(INPUT_POST, 'pickup_date', FILTER_SANITIZE_STRING));
        $pickup_time = trim(filter_input(INPUT_POST, 'pickup_time', FILTER_SANITIZE_STRING));
        $pickup_location = trim(filter_input(INPUT_POST, 'pickup_location', FILTER_SANITIZE_STRING));
        $dropoff_location = trim(filter_input(INPUT_POST, 'dropoff_location', FILTER_SANITIZE_STRING));
        $payment_method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_STRING);
        $total_amount = filter_input(INPUT_POST, 'total_amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $service_id = filter_input(INPUT_POST, 'service_id', FILTER_SANITIZE_NUMBER_INT);
        $hours = filter_input(INPUT_POST, 'hours', FILTER_SANITIZE_NUMBER_INT);

        if (!$name || !$email || !$phone || !$service_type || !$pickup_date || !$pickup_time || !$pickup_location || !$dropoff_location || !$payment_method || !$total_amount || !$service_id) {
            $errorMessage = 'All required fields must be filled.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Invalid email address.';
        } elseif (!in_array($payment_method, ['card', 'paynow'])) {
            $errorMessage = 'Invalid payment method.';
        } elseif (!in_array($service_type, ['Hourly', 'Point-to-Point Transfer', 'Airport Departure', 'Airport Arrival', 'Corporate', 'Events'])) {
            $errorMessage = 'Invalid service type.';
        } elseif ($service_type === 'Hourly' && (!$hours || $hours < 2)) {
            $errorMessage = 'Hourly service requires a minimum of 2 hours.';
        } elseif (strtotime($pickup_date) < strtotime(date('Y-m-d'))) {
            $errorMessage = 'Pickup date cannot be in the past.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pickup_date)) {
            $errorMessage = 'Invalid pickup date format. Use YYYY-MM-DD.';
        } elseif (!preg_match('/^\d{2}:\d{2}$/', $pickup_time)) {
            $errorMessage = 'Invalid pickup time format. Use HH:MM (e.g., 08:00).';
        } else {
            // Normalize pickup_date to Y-m-d and pickup_time to H:i
            $pickup_date = (new DateTime($pickup_date))->format('Y-m-d');
            $pickup_time = substr($pickup_time, 0, 5); // Ensure H:i format

            // Check limousine availability
            $pickup_datetime = "$pickup_date $pickup_time";
            $duration_hours = $service_type === 'Hourly' ? $hours : 2;
            $end_datetime = date('Y-m-d H:i:s', strtotime("$pickup_datetime + $duration_hours hours"));
            $end_time = (new DateTime($end_datetime))->format('H:i');
            $availabilityStmt = $pdo->prepare("
                SELECT COUNT(*) FROM bookings 
                WHERE service_id = ? AND service_type = 'limousine'
                AND status != 'cancelled'
                AND DATE(pickup_date) = ?
                AND (
                    (TIME_FORMAT(pickup_time, '%H:%i') <= ? AND 
                     TIME_FORMAT(ADDTIME(TIME_FORMAT(pickup_time, '%H:%i'), SEC_TO_TIME(COALESCE(hours, 2) * 3600)), '%H:%i') >= ?) OR
                    (TIME_FORMAT(pickup_time, '%H:%i') >= ? AND TIME_FORMAT(pickup_time, '%H:%i') <= ?)
                )
            ");
            $availabilityStmt->execute([
                $service_id, 
                $pickup_date, 
                $end_time, $pickup_time,
                $pickup_time, $end_time
            ]);
            if ($availabilityStmt->fetchColumn() > 0) {
                $errorMessage = 'This limousine is not available for the selected date and time.';
            } elseif ($cutOff) {
                $errorMessage = 'Limousine service is currently unavailable.';
            } else {
                $pdo->beginTransaction();

                $clientStmt = $pdo->prepare("INSERT INTO clients (name, email, phone, service) VALUES (?, ?, ?, ?)");
                $clientStmt->execute([$name, $email, $phone, 'limousine']);
                $client_id = $pdo->lastInsertId();

                $bookingStmt = $pdo->prepare("
                    INSERT INTO bookings (
                        client_id, service_type, service_id, slot_id, total_amount, 
                        pickup_date, pickup_time, pickup_location, dropoff_location, 
                        payment_method, payment_status, hours, limo_service_type
                    ) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $payment_status = 'pending';
                $bookingStmt->execute([
                    $client_id, 'limousine', $service_id, $total_amount,
                    $pickup_date, $pickup_time, $pickup_location, $dropoff_location,
                    $payment_method, $payment_status, $service_type === 'Hourly' ? (int)$hours : null, $service_type
                ]);
                $booking_id = $pdo->lastInsertId();

                // Log booking creation for debugging
                // error_log("Booking created: booking_id=$booking_id, service_id=$service_id, pickup_date=$pickup_date, pickup_time=$pickup_time, limo_service_type=$service_type", 3, '/iconm3/logs/debug.log');

                // Create HitPay Payment Request
                $limoStmt = $pdo->prepare("SELECT model FROM limousines WHERE id = ?");
                $limoStmt->execute([$service_id]);
                $limoModel = $limoStmt->fetchColumn();

                $paymentData = [
                    'name' => 'Limousine Service',
                    'email' => $email,
                    'phone' => $phone,
                    'amount' => number_format($total_amount, 2, '.', ''),
                    'currency' => 'SGD',
                    'redirect_url' => "$baseUrl/limousine-service.php?booking_id=$booking_id",
                    'webhook' => "$baseUrl/iconm3/hitpay_webhook.php",
                    'reference_number' => (string)$booking_id,
                    'description' => $service_type === 'Hourly' ?
                        "Limousine Service: $limoModel | Service Type: $service_type ($hours hours) | Pickup: $pickup_datetime" :
                        "Limousine Service: $limoModel | Service Type: $service_type | Pickup: $pickup_datetime",
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

                $response = curl_exec($ch);
                if (curl_errno($ch)) {
                    throw new Exception('cURL error: ' . curl_error($ch));
                }
                curl_close($ch);

                $responseData = json_decode($response, true);
                if (!$responseData || !isset($responseData['url'])) {
                    throw new Exception('Failed to create HitPay payment request: ' . ($responseData['message'] ?? 'Unknown error'));
                }

                // Update booking with payment_request_id
                $stmt = $pdo->prepare("UPDATE bookings SET payment_request_id = ? WHERE id = ?");
                $stmt->execute([$responseData['id'], $booking_id]);

                $pdo->commit();

                // Store payment request ID in session for verification
                $_SESSION['hitpay_payment_request_id'] = $responseData['id'];

                header('Location: ' . $responseData['url']);
                exit;
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorMessage = "Error: " . $e->getMessage();
        // error_log("Limousine service error: " . $e->getMessage(), 3, '/iconm3/logs/php_errors.log');
    }
}

// Handle success redirection from HitPay
if (isset($_GET['booking_id'])) {
    try {
        $booking_id = filter_input(INPUT_GET, 'booking_id', FILTER_SANITIZE_NUMBER_INT);
        if ($booking_id) {
            $stmt = $pdo->prepare("
                SELECT b.*, c.name, c.email, c.phone, l.model
                FROM bookings b
                JOIN clients c ON b.client_id = c.id
                JOIN limousines l ON b.service_id = l.id
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
    <title>Limousine Service - Icon Services</title>
    <!-- SEO -->
    <meta name="description" content="We provide Car Detailing, Car Rental, Car Servicing, Valet, and Limousine Services in one place.">
    <meta name="keywords" content="singapore car detailing, singapore car rental, singapore car servicing, singapore valet service, singapore limousine service">
    <meta name="author" content="Icon Services">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://iconcarrentalsg.com/limousine-service">
    <meta property="og:site_name" content="Icon Services">
    <meta property="og:description" content="We provide Car Detailing, Car Rental, Car Servicing, Valet, and Limousine Services in one place.">
    <meta property="og:keywords" content="singapore car detailing, singapore car rental, singapore car servicing, singapore valet service, singapore limousine service">
    <!-- GSC Code -->
    <meta name="google-site-verification" content="CODEHERE">
    <!-- BWT Code -->
    <meta name="msvalidate.01" content="CODEHERE">
    <!-- Canonicalization -->
    <link rel="canonical" href="https://iconcarrentalsg.com/limousine-service">
    <!-- Schema -->
    <script type="application/ld+json">
      {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "Icon Services",
        "alternateName": "Icon Services",
        "url": "URL",
        "description": "We provide Car Detailing, Car Rental, Car Servicing, Valet, and Limousine Services in one place.",
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
                <img src="./img/icon-limousine-service.png" alt="Icon Services Logo" class="logo" style="max-height: 150px;">
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
            <h2 class="text-center fw-bold mb-5 animate__animated animate__fadeIn">Limousine Services</h2>
            <p class="lead text-center mb-5">Travel in style with our luxury limousine services.</p>

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
                        <p><strong>Limousine:</strong> <?php echo htmlspecialchars($bookingDetails['model']); ?></p>
                        <p><strong>Service Type:</strong> <?php echo htmlspecialchars($bookingDetails['limo_service_type']); ?></p>
                        <?php if ($bookingDetails['hours']): ?>
                            <p><strong>Hours:</strong> <?php echo htmlspecialchars($bookingDetails['hours']); ?></p>
                        <?php endif; ?>
                        <p><strong>Pickup Date & Time:</strong> <?php echo htmlspecialchars($bookingDetails['pickup_date'] . ' ' . $bookingDetails['pickup_time']); ?></p>
                        <p><strong>Pickup Location:</strong> <?php echo htmlspecialchars($bookingDetails['pickup_location']); ?></p>
                        <p><strong>Dropoff Location:</strong> <?php echo htmlspecialchars($bookingDetails['dropoff_location']); ?></p>
                        <p><strong>Total Amount:</strong> S$<?php echo number_format($bookingDetails['total_amount'], 2); ?></p>
                        <p><strong>Payment Method:</strong> <?php echo htmlspecialchars($bookingDetails['payment_method']); ?></p>
                        <p><strong>Payment Status:</strong> <?php echo htmlspecialchars($bookingDetails['payment_status']); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <?php foreach ($limousines as $limo): ?>
                    <div class="col-md-4">
                        <div class="card border-light shadow-sm h-100">
                            <?php if ($limo['image']): ?>
                                <img src="./img/<?php echo htmlspecialchars($limo['image']); ?>" alt="<?php echo htmlspecialchars($limo['model']); ?>" class="card-img-top">
                            <?php else: ?>
                                <img src="./img/default-limo.jpg" alt="Default Limousine Image" class="card-img-top">
                            <?php endif; ?>
                            <div class="card-body text-center">
                                <h3 class="card-title fw-bold mb-3"><?php echo htmlspecialchars($limo['model']); ?></h3>
                                <p class="card-text mb-2"><?php echo htmlspecialchars($limo['description'] ?: 'No description available'); ?></p>
                                <button class="btn btn-primary w-100 book-service" data-limo-id="<?php echo $limo['id']; ?>" data-limo-model="<?php echo htmlspecialchars($limo['model']); ?>" data-service-types="<?php echo htmlspecialchars(json_encode(json_decode($limo['service_types'], true))); ?>" data-bs-toggle="modal" data-bs-target="#bookingModal" <?php echo $cutOff ? 'disabled' : ''; ?>>Book Now</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($cutOff): ?>
                <p class="text-center text-danger mt-4">Limousine bookings are currently closed.</p>
            <?php endif; ?>
        </div>
    </section>

    <div class="modal fade" id="bookingModal" tabindex="-1" aria-labelledby="bookingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bookingModalLabel">Schedule Your Limousine Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h4 id="selected-limo" class="text-center mb-4"></h4>
                    <form id="booking-form" method="POST">
                        <input type="hidden" name="service_type" value="limousine">
                        <input type="hidden" name="service_id" id="service_id">
                        <input type="hidden" name="total_amount" id="total_amount">
                        <input type="hidden" name="hours" id="hours_hidden">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="service-type" class="form-label">Service Type</label>
                                <select id="service-type" name="service_type" class="form-control" required>
                                    <option value="">Select a service type</option>
                                    <option value="Hourly">Hourly</option>
                                    <option value="Point-to-Point Transfer">Point-to-Point Transfer</option>
                                    <option value="Airport Departure">Airport Departure</option>
                                    <option value="Airport Arrival">Airport Arrival</option>
                                    <option value="Corporate">Corporate</option>
                                    <option value="Events">Events</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="total-amount" class="form-label">Total Amount</label>
                                <input type="text" id="total-amount" class="form-control" readonly value="S$0.00">
                            </div>
                        </div>
                        <div class="row mb-3" id="hours-section" style="display: none;">
                            <div class="col-md-6">
                                <label for="hours" class="form-label">Number of Hours (Minimum 2)</label>
                                <input type="number" id="hours" name="hours" class="form-control" min="2" value="2">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="pickup_date" class="form-label">Pickup Date</label>
                                <input type="date" id="pickup_date" name="pickup_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="pickup_time" class="form-label">Pickup Time</label>
                                <select id="pickup_time" name="pickup_time" class="form-control" required>
                                    <option value="">Select a date first</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="pickup_location" class="form-label">Pickup Location</label>
                                <input type="text" id="pickup_location" name="pickup_location" class="form-control" required placeholder="Enter pickup address">
                            </div>
                            <div class="col-md-6">
                                <label for="dropoff_location" class="form-label">Dropoff Location</label>
                                <input type="text" id="dropoff_location" name="dropoff_location" class="form-control" required placeholder="Enter dropoff address">
                            </div>
                        </div>
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
        let totalAmount = 0;
        let selectedLimoId = null;
        let selectedServiceType = null;
        let serviceTypes = {};
        const allTimeSlots = <?php echo json_encode($availableTimes); ?>; // Available times from PHP

        document.querySelectorAll('.book-service').forEach(button => {
            button.addEventListener('click', () => {
                selectedLimoId = button.getAttribute('data-limo-id');
                const limoModel = button.getAttribute('data-limo-model');
                serviceTypes = JSON.parse(button.getAttribute('data-service-types'));
                document.getElementById('selected-limo').textContent = `Booking: ${limoModel}`;
                document.querySelector('input[name="service_id"]').value = selectedLimoId;
                document.getElementById('service-type').value = '';
                document.getElementById('total-amount').value = 'S$0.00';
                document.getElementById('pickup_date').value = '';
                document.getElementById('pickup_time').innerHTML = '<option value="">Select a date first</option>';
                document.getElementById('pickup_location').value = '';
                document.getElementById('dropoff_location').value = '';
                document.getElementById('hours-section').style.display = 'none';
                document.getElementById('hours').value = 2;
                document.getElementById('booking-form').reset();
            });
        });

        document.getElementById('service-type').addEventListener('change', function() {
            selectedServiceType = this.value;
            document.getElementById('hours-section').style.display = selectedServiceType === 'Hourly' ? 'block' : 'none';
            updateTotalAmount();
        });

        document.getElementById('pickup_date').addEventListener('change', function() {
            updateAvailableTimes(); // Update available times when date changes
        });

        document.getElementById('hours').addEventListener('input', function() {
            updateTotalAmount();
        });

        function updateTotalAmount() {
            const hours = parseInt(document.getElementById('hours').value) || 2;
            totalAmount = selectedServiceType === 'Hourly' ? (serviceTypes[selectedServiceType] || 0) * hours : (serviceTypes[selectedServiceType] || 0);
            document.getElementById('total-amount').value = `S$${totalAmount.toFixed(2)}`;
            document.querySelector('input[name="total_amount"]').value = totalAmount;
            document.querySelector('input[name="hours"]').value = selectedServiceType === 'Hourly' ? hours : '';
        }

        function updateAvailableTimes() {
            const pickupDate = document.getElementById('pickup_date').value;
            const pickupTimeSelect = document.getElementById('pickup_time');

            if (!pickupDate || !selectedLimoId) {
                pickupTimeSelect.innerHTML = '<option value="">Select a date first</option>';
                return;
            }

            // Fetch unavailable slots for the selected limousine and date
            fetch(`./iconm3/get_unavailable_slots_limousine.php?limousine_id=${selectedLimoId}&date=${pickupDate}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        console.error('Server returned error:', data.error);
                        pickupTimeSelect.innerHTML = '<option value="">Error loading slots</option>';
                        return;
                    }

                    const unavailableSlots = data.unavailable_slots || [];
                    // console.log('Unavailable slots:', unavailableSlots); // Debug log
                    const availableSlots = allTimeSlots.filter(slot => !unavailableSlots.includes(slot));

                    if (availableSlots.length === 0) {
                        pickupTimeSelect.innerHTML = '<option value="">No available slots</option>';
                    } else {
                        pickupTimeSelect.innerHTML = '<option value="">Select a time</option>' + 
                            availableSlots.map(slot => `<option value="${slot}">${slot}</option>`).join('');
                    }
                })
                .catch(error => {
                    console.error('Error fetching unavailable slots:', error);
                    pickupTimeSelect.innerHTML = '<option value="">Error loading slots</option>';
                });
        }

        document.getElementById('bookingModal').addEventListener('hidden.bs.modal', () => {
            totalAmount = 0;
            selectedLimoId = null;
            selectedServiceType = null;
            serviceTypes = {};
            document.getElementById('selected-limo').textContent = '';
            document.getElementById('service-type').value = '';
            document.getElementById('total-amount').value = 'S$0.00';
            document.getElementById('pickup_date').value = '';
            document.getElementById('pickup_time').innerHTML = '<option value="">Select a date first</option>';
            document.getElementById('pickup_location').value = '';
            document.getElementById('dropoff_location').value = '';
            document.getElementById('hours-section').style.display = 'none';
            document.getElementById('hours').value = 2;
            document.getElementById('booking-form').reset();
        });

        // Chatbox toggle functionality
        document.querySelector('.chatbox-toggle').addEventListener('click', function () {
            const content = document.querySelector('.chatbox-content');
            content.style.display = content.style.display === 'block' ? 'none' : 'block';
        });
    </script>
</body>
</html>