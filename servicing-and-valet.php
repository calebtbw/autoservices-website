<?php
require 'iconm3/db.php';
require 'iconm3/config.php';
session_start();

// Prevent crawlers for booking-related actions
header('X-Robots-Tag: noindex, nofollow');

$successMessage = '';
$errorMessage = '';
$bookingDetails = null;

// Fetch valet services
$stmt = $pdo->query("SELECT * FROM valet_services");
$valet_services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch car servicing options
$stmt = $pdo->query("SELECT * FROM car_services");
$car_services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if valet service is cut off
$cutOffStmt = $pdo->query("SELECT value FROM settings WHERE name = 'valet_cutoff'");
$cutOff = $cutOffStmt->fetchColumn() == '1';

// Check if car servicing is cut off
$cutOffStmt = $pdo->query("SELECT value FROM settings WHERE name = 'car_servicing_cutoff'");
$carServicingCutOff = $cutOffStmt->fetchColumn() == '1';

// Fetch slots for the next 3 months to preload
$currentMonth = date('Y-m');
$endMonth = date('Y-m', strtotime('+2 months'));
$startDate = "$currentMonth-01";
$endDate = date('Y-m-t', strtotime($endMonth));

$stmt = $pdo->prepare("SELECT * FROM slots WHERE service_type IN ('valet', 'car_servicing') AND slot_date BETWEEN ? AND ?");
$stmt->execute([$startDate, $endDate]);
$allSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        $service_type = filter_input(INPUT_POST, 'service_type', FILTER_SANITIZE_STRING);
        $slot_id = filter_input(INPUT_POST, 'slot_id', FILTER_SANITIZE_NUMBER_INT);
        $service_id = filter_input(INPUT_POST, 'service_id', FILTER_SANITIZE_NUMBER_INT);
        $payment_method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_STRING);
        $total_amount = filter_input(INPUT_POST, 'total_amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $valet_included = filter_input(INPUT_POST, 'valet_included', FILTER_SANITIZE_NUMBER_INT) ?? 0;

        if (!$name || !$email || !$phone || !$service_type || !$slot_id || !$service_id || !$payment_method || !$total_amount) {
            $errorMessage = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Invalid email address.';
        } elseif (!in_array($payment_method, ['card', 'paynow'])) {
            $errorMessage = 'Invalid payment method.';
        } elseif (!in_array($service_type, ['valet', 'car_servicing'])) {
            $errorMessage = 'Invalid service type.';
        } else {
            // Validate service_id
            $serviceStmt = $pdo->prepare(
                $service_type === 'valet' ?
                "SELECT name, price FROM valet_services WHERE id = ?" :
                "SELECT name, price, valet_price FROM car_services WHERE id = ?"
            );
            $serviceStmt->execute([$service_id]);
            $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$service) {
                $errorMessage = 'Invalid service selected.';
            } else {
                // Check if slot is still available
                $slotCheckStmt = $pdo->prepare("SELECT is_booked, slot_date, slot_time FROM slots WHERE id = ? AND service_type = ?");
                $slotCheckStmt->execute([$slot_id, $service_type]);
                $slot = $slotCheckStmt->fetch(PDO::FETCH_ASSOC);

                if (!$slot || $slot['is_booked'] == 1) {
                    $errorMessage = 'Selected slot is no longer available.';
                } elseif (($service_type === 'valet' && $cutOff) || ($service_type === 'car_servicing' && $carServicingCutOff)) {
                    $errorMessage = ucfirst($service_type) . ' service is currently unavailable.';
                } else {
                    $pdo->beginTransaction();

                    // Insert client
                    $clientStmt = $pdo->prepare("INSERT INTO clients (name, email, phone, service) VALUES (?, ?, ?, ?)");
                    $clientStmt->execute([$name, $email, $phone, $service_type]);
                    $client_id = $pdo->lastInsertId();

                    // Mark slot as booked
                    $updateSlotStmt = $pdo->prepare("UPDATE slots SET is_booked = 1 WHERE id = ?");
                    $updateSlotStmt->execute([$slot_id]);

                    // Use slot_date and slot_time for pickup_date and pickup_time
                    $pickup_date = $slot['slot_date'];
                    $pickup_time = $slot['slot_time'];
                    $booking_date_display = $pickup_date . ' ' . $pickup_time;

                    // Verify total_amount
                    $expected_amount = $service_type === 'valet' ? $service['price'] : $service['price'] + ($valet_included ? $service['valet_price'] : 0);
                    if (abs($total_amount - $expected_amount) > 0.01) {
                        throw new Exception('Invalid total amount.');
                    }

                    // Insert booking
                    $bookingStmt = $pdo->prepare("
                        INSERT INTO bookings (
                            client_id, service_type, service_id, slot_id, total_amount,
                            pickup_date, pickup_time, pickup_location, dropoff_location,
                            payment_method, payment_status, valet_included
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $payment_status = 'pending';
                    $pickup_location = '';
                    $dropoff_location = '';
                    $bookingStmt->execute([
                        $client_id, $service_type, $service_id, $slot_id, $total_amount,
                        $pickup_date, $pickup_time, $pickup_location, $dropoff_location,
                        $payment_method, $payment_status, $valet_included
                    ]);
                    $booking_id = $pdo->lastInsertId();

                    // Create HitPay Payment Request
                    $description = $service_type === 'valet' ?
                        "Valet Service: {$service['name']} | Date & Time: $booking_date_display" :
                        "Car Servicing: {$service['name']}" . ($valet_included ? ' with Valet' : '') . " | Date & Time: $booking_date_display";
                    $paymentData = [
                        'name' => $service_type === 'valet' ? 'Valet Service' : 'Car Servicing',
                        'email' => $email,
                        'phone' => $phone,
                        'amount' => number_format($total_amount, 2, '.', ''),
                        'currency' => 'SGD',
                        'redirect_url' => "$baseUrl/servicing-and-valet.php?booking_id=$booking_id",
                        'webhook' => "$baseUrl/iconm3/hitpay_webhook.php",
                        'reference_number' => (string)$booking_id,
                        'description' => $description,
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
                        throw new Exception('Payment gateway connection error: ' . curl_error($ch));
                    }
                    curl_close($ch);

                    $responseData = json_decode($response, true);
                    if (!$responseData || !isset($responseData['url'])) {
                        throw new Exception('Failed to create payment request: ' . ($responseData['message'] ?? 'Unknown error'));
                    }

                    // Update booking with payment_request_id
                    $stmt = $pdo->prepare("UPDATE bookings SET payment_request_id = ? WHERE id = ?");
                    $stmt->execute([$responseData['id'], $booking_id]);

                    $pdo->commit();

                    // Store payment request ID in session
                    $_SESSION['hitpay_payment_request_id'] = $responseData['id'];

                    // Redirect to HitPay payment page
                    header('Location: ' . $responseData['url']);
                    exit;
                }
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorMessage = "Error: " . $e->getMessage();
    }
}

// Handle success redirection from HitPay
if (isset($_GET['booking_id'])) {
    try {
        $booking_id = filter_input(INPUT_GET, 'booking_id', FILTER_SANITIZE_NUMBER_INT);
        if ($booking_id) {
            $stmt = $pdo->prepare("
                SELECT b.*, c.name, c.email, c.phone,
                       COALESCE(vs.name, cs.name) as service_name
                FROM bookings b
                JOIN clients c ON b.client_id = c.id
                LEFT JOIN valet_services vs ON b.service_type = 'valet' AND b.service_id = vs.id
                LEFT JOIN car_services cs ON b.service_type = 'car_servicing' AND b.service_id = cs.id
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
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>Servicing & Valet - Icon Services</title>
    <meta name="description" content="Premium Valet and Car Servicing, with optional valet for workshop visits.">
    <meta name="keywords" content="singapore valet service, singapore car servicing, car maintenance">
    <meta name="author" content="Icon Services">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://iconcarrentalsg.com/servicing-and-valet">
    <meta property="og:site_name" content="Icon Services">
    <meta property="og:description" content="Premium Valet and Car Servicing, with optional valet for workshop visits.">
    <meta property="og:keywords" content="singapore valet service, singapore car servicing, car maintenance">
    <meta name="google-site-verification" content="CODEHERE">
    <meta name="msvalidate.01" content="CODEHERE">
    <link rel="canonical" href="https://iconcarrentalsg.com/servicing-and-valet">
    <script type="application/ld+json">
      {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "Icon Services",
        "alternateName": "Icon Services",
        "url": "URL",
        "description": "Premium Valet and Car Servicing, with optional valet for workshop visits.",
        "logo": "https://iconcarrentalsg.com/img/icon.png",
        "sameAs": [
          "https://www.facebook.com/HERE/",
          "https://tiktok.com/HERE/",
          "https://www.instagram.com/HERE/"
        ]
      }
    </script>
    <script async src="https://www.googletagmanager.com/gtag/js?id=IDHERE"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag() { dataLayer.push(arguments); }
      gtag("js", new Date());
      gtag("config", "IDHERE");
    </script>
    <link rel="icon" href="./img/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="./css/styles.css">
    <style>
        .spinner-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid #fff;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
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
                    <li class="nav-item"><a class="nav-link" href="./">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="./car-detailing">Car Detailing</a></li>
                    <li class="nav-item"><a class="nav-link" href="./car-rental">Car Rental</a></li>
                    <li class="nav-item"><a class="nav-link active" href="./servicing-and-valet">Servicing & Valet</a></li>
                    <li class="nav-item"><a class="nav-link" href="./limousine-service">Limousine</a></li>
                    <li class="nav-item"><a class="nav-link" href="./contact-us">Contact Us</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <section class="py-5 bg-light" style="padding-top: 8rem !important;">
        <div class="container">
            <h2 class="text-center fw-bold mb-5 animate__animated animate__fadeIn">Servicing & Valet</h2>
            <p class="lead text-center mb-5">Premium drive-home valet and professional car servicing, with optional valet for workshop visits.</p>

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
                        <p><strong>Service:</strong> <?php echo htmlspecialchars($bookingDetails['service_name']); ?></p>
                        <?php if ($bookingDetails['service_type'] === 'car_servicing'): ?>
                            <p><strong>Valet Included:</strong> <?php echo $bookingDetails['valet_included'] ? 'Yes' : 'No'; ?></p>
                        <?php endif; ?>
                        <p><strong>Date & Time:</strong> <?php echo htmlspecialchars($bookingDetails['pickup_date'] . ' ' . $bookingDetails['pickup_time']); ?></p>
                        <p><strong>Total Amount:</strong> S$<?php echo number_format($bookingDetails['total_amount'], 2); ?></p>
                        <p><strong>Payment Method:</strong> <?php echo htmlspecialchars($bookingDetails['payment_method']); ?></p>
                        <p><strong>Payment Status:</strong> <?php echo htmlspecialchars($bookingDetails['payment_status']); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <h3 class="fw-bold mb-4" style="text-align: center;">Car Servicing</h3>
            <div class="row g-4 mb-5">
                <?php foreach ($car_services as $service): ?>
                    <div class="col-md-4">
                        <div class="card border-light shadow-sm h-100">
                            <div class="card-body text-center">
                                <h3 class="card-title fw-bold mb-3"><?php echo htmlspecialchars($service['name']); ?></h3>
                                <p class="card-text mb-4"><?php echo htmlspecialchars($service['description']); ?></p>
                                <p class="fw-bold text-primary">S$<?php echo number_format($service['price'], 2); ?> (Without Valet)</p>
                                <p class="fw-bold text-primary">S$<?php echo number_format($service['price'] + $service['valet_price'], 2); ?> (With Valet)</p>
                                <button class="btn btn-primary w-100 book-service" 
                                    data-service="<?php echo htmlspecialchars($service['name']); ?>" 
                                    data-service-id="<?php echo $service['id']; ?>" 
                                    data-price="<?php echo $service['price']; ?>" 
                                    data-valet-price="<?php echo $service['valet_price']; ?>" 
                                    data-service-type="car_servicing"
                                    data-bs-toggle="modal" 
                                    data-bs-target="#valetOptionModal" 
                                    <?php echo $carServicingCutOff ? 'disabled' : ''; ?>>Book Now</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <hr>
            <br>

            <h3 class="fw-bold mb-4" style="text-align: center;">Valet Services</h3>
            <div class="row g-4">
                <?php foreach ($valet_services as $valet): ?>
                    <div class="col-md-4">
                        <div class="card border-light shadow-sm h-100">
                            <div class="card-body text-center">
                                <h3 class="card-title fw-bold mb-3"><?php echo htmlspecialchars($valet['name']); ?></h3>
                                <p class="card-text mb-4"><?php echo htmlspecialchars($valet['description']); ?></p>
                                <p class="fw-bold text-primary">S$<?php echo number_format($valet['price'], 2); ?></p>
                                <button class="btn btn-primary w-100 book-service" 
                                    data-service="<?php echo htmlspecialchars($valet['name']); ?>" 
                                    data-service-id="<?php echo $valet['id']; ?>" 
                                    data-price="<?php echo $valet['price']; ?>" 
                                    data-service-type="valet"
                                    data-bs-toggle="modal" 
                                    data-bs-target="#bookingModal" 
                                    <?php echo $cutOff ? 'disabled' : ''; ?>>Book Now</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($cutOff && $carServicingCutOff): ?>
                <p class="text-center text-danger mt-4">Valet and car servicing bookings are currently closed.</p>
            <?php elseif ($cutOff): ?>
                <p class="text-center text-danger mt-4">Valet bookings are currently closed.</p>
            <?php elseif ($carServicingCutOff): ?>
                <p class="text-center text-danger mt-4">Car servicing bookings are currently closed.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- Valet Option Modal for Car Servicing -->
    <div class="modal fade" id="valetOptionModal" tabindex="-1" aria-labelledby="valetOptionModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="valetOptionModalLabel">Select Valet Option</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h4 id="valet-option-service" class="mb-4"></h4>
                    <div class="mb-3">
                        <label class="form-label">Include Valet Service?</label>
                        <div>
                            <input type="radio" id="valet_yes" name="valet_option" value="1" checked>
                            <label for="valet_yes">Yes, drive my car to the workshop (Additional S$<span id="valet-price-display">0.00</span>)</label>
                        </div>
                        <div>
                            <input type="radio" id="valet_no" name="valet_option" value="0">
                            <label for="valet_no">No, I will bring my car to the workshop</label>
                        </div>
                    </div>
                    <p><strong>Total Amount: </strong><span id="valet-total-amount">$0.00</span></p>
                    <button class="btn btn-primary w-100" id="proceed-to-booking">Proceed to Booking</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Booking Modal -->
    <div class="modal fade" id="bookingModal" tabindex="-1" aria-labelledby="bookingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bookingModalLabel">Schedule Your Service</h5>
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
                        <input type="hidden" name="service_type" id="service-type">
                        <input type="hidden" name="service_id">
                        <input type="hidden" name="slot_id">
                        <input type="hidden" name="total_amount">
                        <input type="hidden" name="valet_included" id="valet-included">
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

    <div class="spinner-overlay" id="spinner">
        <div class="spinner"></div>
    </div>

    <section class="reviews-section">
        <div class="container">
            <h2 class="text-center fw-bold mb-5 animate__animated animate__fadeIn">What Our Customers Say</h2>
            <div class="row">
                <div class="col-md-4">
                    <div class="review-card">
                        <div class="stars">★★★★★</div>
                        <p class="review-text">"The valet service was a lifesaver after a late night!"</p>
                        <p class="reviewer-name">Sarah L.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="review-card">
                        <div class="stars">★★★★★</div>
                        <p class="review-text">"Car servicing with valet was so convenient."</p>
                        <p class="reviewer-name">James R.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="review-card">
                        <div class="stars">★★★★☆</div>
                        <p class="review-text">"Professional and reliable service."</p>
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
                            What is included in your valet service?
                        </button>
                    </h2>
                    <div id="faqCollapse1" class="accordion-collapse collapse show" aria-labelledby="faqHeading1" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Our valet service provides a professional driver to safely drive you and your vehicle home, ensuring convenience and safety.
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="faqHeading2">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse2" aria-expanded="false" aria-controls="faqCollapse2">
                            What does car servicing with valet entail?
                        </button>
                    </h2>
                    <div id="faqCollapse2" class="accordion-collapse collapse" aria-labelledby="faqHeading2" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            With valet, we pick up your car, drive it to our partner workshop for servicing, and return it to you, saving you time and hassle.
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="faqHeading3">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse3" aria-expanded="false" aria-controls="faqCollapse3">
                            How do I book a service?
                        </button>
                    </h2>
                    <div id="faqCollapse3" class="accordion-collapse collapse" aria-labelledby="faqHeading3" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Select a service, choose a date and time, and confirm your booking through our secure payment process.
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

    <footer class="footer-section">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-4">
                    <a href="./">
                        <img src="./img/icon.png" alt="Icon Services Logo" class="footer-logo mb-3" style="max-width: 150px;">
                    </a>
                    <p class="text-white">Icon Services Pte. Ltd.<br>UEN: XXYYZZ123</p>
                </div>
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
                    <p class="text-white" style="font-size: x-small;">Developed by <a href="https://nxstudios.sg" class="footer-link">NXStudios</a>, a part of NXGroup.</p>
                </div>
            </div>
            <div class="text-center text-white mt-4 pt-3 border-top">
                <p class="mb-0">Copyright © 2025 Icon Services Pte. Ltd. All Rights Reserved.</p>
            </div>
        </div>
    </footer>

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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="./js/script.js"></script>
    <script>
        let selectedDate = null;
        let selectedSlotId = null;
        let selectedServiceId = null;
        let selectedServiceType = null;
        let selectedServiceName = null; // Added to store service name
        let totalAmount = 0;
        let valetIncluded = 0;
        const allSlots = <?php echo json_encode($allSlots); ?>;

        // Handle book service button click
        $('.book-service').on('click', function() {
            selectedServiceName = $(this).data('service'); // Store service name
            selectedServiceId = $(this).data('service-id');
            selectedServiceType = $(this).data('service-type');
            const price = parseFloat($(this).data('price'));
            const valetPrice = parseFloat($(this).data('valet-price') || 0);

            if (selectedServiceType === 'valet') {
                totalAmount = price;
                valetIncluded = 0;
                $('#selected-service').text(`Valet Service: ${selectedServiceName}`);
                $('#booking-form input[name="service_id"]').val(selectedServiceId);
                $('#booking-form input[name="service_type"]').val(selectedServiceType);
                $('#booking-form input[name="total_amount"]').val(totalAmount.toFixed(2));
                $('#booking-form input[name="valet_included"]').val(valetIncluded);
                $('#date-picker').val('');
                $('#time-slots').html('');
                $('#booking-form').addClass('d-none');
                $('#valetOptionModal').modal('hide');
                $('#bookingModal').modal('show');
            } else {
                $('#valet-option-service').text(`Car Servicing: ${selectedServiceName}`);
                $('#valet-price-display').text(valetPrice.toFixed(2));
                $('#valet-total-amount').text(`S$${(price + valetPrice).toFixed(2)}`);
                $('#valet_yes').prop('checked', true);
                totalAmount = price + valetPrice;
                valetIncluded = 1;
                $('#booking-form input[name="service_id"]').val(selectedServiceId);
                $('#booking-form input[name="service_type"]').val(selectedServiceType);
                $('#booking-form input[name="total_amount"]').val(totalAmount.toFixed(2));
                $('#booking-form input[name="valet_included"]').val(valetIncluded);
            }
        });

        // Handle valet option change
        $('input[name="valet_option"]').on('change', function() {
            const price = parseFloat($('.book-service[data-service-id="' + selectedServiceId + '"]').data('price'));
            const valetPrice = parseFloat($('.book-service[data-service-id="' + selectedServiceId + '"]').data('valet-price'));
            valetIncluded = parseInt(this.value);
            totalAmount = valetIncluded ? price + valetPrice : price;
            $('#valet-total-amount').text(`S$${totalAmount.toFixed(2)}`);
            $('#booking-form input[name="total_amount"]').val(totalAmount.toFixed(2));
            $('#booking-form input[name="valet_included"]').val(valetIncluded);
        });

        // Proceed to booking modal
        $('#proceed-to-booking').on('click', function() {
            if (!selectedServiceId || !selectedServiceName) {
                alert('Error: No service selected. Please try again.');
                return;
            }
            $('#selected-service').text(`Car Servicing: ${selectedServiceName}` + (valetIncluded ? ' with Valet' : ''));
            $('#date-picker').val('');
            $('#time-slots').html('');
            $('#booking-form').addClass('d-none');
            $('#valetOptionModal').modal('hide');
            $('#bookingModal').modal('show');
        });

        // Handle date change
        $('#date-picker').on('change', function() {
            selectedDate = this.value;
            loadTimeSlots();
        });

        // Load time slots
        function loadTimeSlots() {
            const timeSlotsDiv = $('#time-slots');
            timeSlotsDiv.html('<h5>Select a Time Slot</h5>');
            if (!selectedDate || !selectedServiceType) {
                timeSlotsDiv.append('<p>Please select a date.</p>');
                return;
            }

            const daySlots = allSlots.filter(slot => slot.slot_date === selectedDate && slot.service_type === selectedServiceType);
            const availableSlots = daySlots.filter(slot => !slot.is_booked);
            if (availableSlots.length === 0) {
                timeSlotsDiv.append('<p>No available slots for this day.</p>');
                return;
            }

            const slotsList = $('<div class="d-flex flex-wrap gap-2"></div>');
            availableSlots.forEach(slot => {
                const button = $('<button class="btn btn-outline-primary"></button>')
                    .text(slot.slot_time.slice(0, 5))
                    .on('click', function() {
                        $('#time-slots button').removeClass('active');
                        $(this).addClass('active');
                        selectedSlotId = slot.id;
                        $('#booking-form').removeClass('d-none');
                        $('#booking-form input[name="slot_id"]').val(selectedSlotId);
                    });
                slotsList.append(button);
            });
            timeSlotsDiv.append(slotsList);
        }

        // Reset booking modal on close
        $('#bookingModal').on('hidden.bs.modal', function() {
            $('#selected-service').text('');
            $('#date-picker').val('');
            $('#time-slots').html('');
            $('#booking-form').addClass('d-none');
            selectedDate = null;
            selectedSlotId = null;
            selectedServiceId = null;
            selectedServiceType = null;
            selectedServiceName = null;
            totalAmount = 0;
            valetIncluded = 0;
        });

        // Show spinner on form submission
        $('#booking-form').on('submit', function() {
            $('#spinner').css('display', 'flex');
        });

        // Chatbox toggle
        $('.chatbox-toggle').on('click', function() {
            const content = $('.chatbox-content');
            content.css('display', content.css('display') === 'block' ? 'none' : 'block');
        });
    </script>
</body>
</html>