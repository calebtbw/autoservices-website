<?php
require 'iconm3/db.php';
require 'iconm3/config.php';
session_start();

// Prevent crawlers for booking-related actions
header('X-Robots-Tag: noindex, nofollow');

$successMessage = '';
$errorMessage = '';
$bookingDetails = null;

// Pagination settings
$vehiclesPerPage = 6;
$page = isset($_GET['page']) ? max(1, filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT)) : 1;
$offset = ($page - 1) * $vehiclesPerPage;

// Search and sorting
$searchQuery = isset($_GET['search']) ? trim(filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING)) : '';
$sortBy = isset($_GET['sort']) && $_GET['sort'] === 'nearest' ? 'nearest' : 'default';
$userLat = isset($_GET['lat']) ? filter_input(INPUT_GET, 'lat', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null;
$userLng = isset($_GET['lng']) ? filter_input(INPUT_GET, 'lng', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null;

// Fetch vehicles with search and sorting
$whereClause = "WHERE availability = 1";
$params = [];

if ($searchQuery) {
    $whereClause .= " AND (model LIKE ? OR address LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

$orderBy = "ORDER BY id ASC";
if ($sortBy === 'nearest' && $userLat !== null && $userLng !== null) {
    $orderBy = "ORDER BY (
        6371 * acos(
            cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) +
            sin(radians(?)) * sin(radians(latitude))
        )
    ) ASC";
    array_unshift($params, $userLat, $userLng, $userLat);
}

// Count total vehicles for pagination
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles $whereClause");
$countStmt->execute($params);
$totalVehicles = $countStmt->fetchColumn();
$totalPages = ceil($totalVehicles / $vehiclesPerPage);

// Fetch vehicles for current page
$query = "SELECT * FROM vehicles $whereClause $orderBy LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($query);

// Bind parameters, ensuring LIMIT and OFFSET are integers
$paramIndex = 1;
foreach ($params as $param) {
    $stmt->bindValue($paramIndex++, $param);
}
$stmt->bindValue($paramIndex++, $vehiclesPerPage, PDO::PARAM_INT);
$stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);

$stmt->execute();
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if car rental service is cut off
$cutOffStmt = $pdo->query("SELECT value FROM settings WHERE name = 'car_rental_cutoff'");
$cutOff = $cutOffStmt->fetchColumn() == '1';

// Determine the base URL dynamically
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = "$protocol://$host/icon-staging";

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm-booking'])) {
    try {
        $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
        $pickup_date = filter_input(INPUT_POST, 'pickup_date', FILTER_SANITIZE_STRING);
        $pickup_time = filter_input(INPUT_POST, 'pickup_time', FILTER_SANITIZE_STRING);
        $dropoff_date = filter_input(INPUT_POST, 'dropoff_date', FILTER_SANITIZE_STRING);
        $dropoff_time = filter_input(INPUT_POST, 'dropoff_time', FILTER_SANITIZE_STRING);
        $pickup_location = filter_input(INPUT_POST, 'pickup_location', FILTER_SANITIZE_STRING);
        $dropoff_location = filter_input(INPUT_POST, 'dropoff_location', FILTER_SANITIZE_STRING);
        $payment_method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_STRING);
        $total_amount = filter_input(INPUT_POST, 'total_amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $service_id = filter_input(INPUT_POST, 'service_id', FILTER_SANITIZE_NUMBER_INT);
        $rental_type = filter_input(INPUT_POST, 'rental_type', FILTER_SANITIZE_STRING);

        if (!$name || !$email || !$phone || !$pickup_date || !$pickup_time || !$dropoff_date || !$dropoff_time || !$pickup_location || !$dropoff_location || !$payment_method || !$total_amount || !$service_id || !$rental_type) {
            $errorMessage = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Invalid email address.';
        } elseif (!in_array($payment_method, ['card', 'paynow'])) {
            $errorMessage = 'Invalid payment method.';
        } elseif (!in_array($rental_type, ['hourly', 'daily'])) {
            $errorMessage = 'Invalid rental type.';
        } else {
            $pickup_datetime = new DateTime("$pickup_date $pickup_time");
            $dropoff_datetime = new DateTime("$dropoff_date $dropoff_time");
            $current_datetime = new DateTime();

            // Validate times
            if ($rental_type === 'daily') {
                if ($pickup_time !== '07:00' || $dropoff_time !== '23:00') {
                    $errorMessage = 'Daily rentals must have pickup at 7:00 AM and drop-off at 11:00 PM.';
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
                    $errorMessage = 'Hourly rental times must be between 7:00 AM and 11:00 PM.';
                }
            }

            if (!$errorMessage) {
                $duration_hours = ($dropoff_datetime->getTimestamp() - $pickup_datetime->getTimestamp()) / (60 * 60);
                $duration_days = ceil($duration_hours / 24);

                if ($pickup_datetime < $current_datetime) {
                    $errorMessage = 'Pickup date and time must be in the future.';
                } elseif ($dropoff_datetime <= $pickup_datetime) {
                    $errorMessage = 'Drop-off date and time must be after the pickup date and time.';
                } elseif ($rental_type === 'hourly' && $duration_hours < 2) {
                    $errorMessage = 'Minimum hourly rental duration is 2 hours.';
                } elseif ($rental_type === 'daily' && $duration_days < 1) {
                    $errorMessage = 'Minimum daily rental duration is 1 day.';
                } elseif ($cutOff) {
                    $errorMessage = 'Car rental service is currently unavailable.';
                }
            }

            if (!$errorMessage) {
                // Check for overlapping bookings
                $overlapStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM bookings
                    WHERE service_type = 'car_rental'
                    AND service_id = ?
                    AND status != 'cancelled'
                    AND (
                        (pickup_date <= ? AND dropoff_date >= ?)
                        OR (pickup_date >= ? AND pickup_date <= ?)
                        OR (dropoff_date >= ? AND dropoff_date <= ?)
                    )
                ");
                $pickup_full = $pickup_datetime->format('Y-m-d H:i:s');
                $dropoff_full = $dropoff_datetime->format('Y-m-d H:i:s');
                $overlapStmt->execute([
                    $service_id,
                    $dropoff_full, $pickup_full,
                    $pickup_full, $dropoff_full,
                    $pickup_full, $dropoff_full
                ]);
                if ($overlapStmt->fetchColumn() > 0) {
                    $errorMessage = 'The selected car is not available during the chosen period.';
                } else {
                    // Begin transaction
                    $pdo->beginTransaction();

                    // Insert client
                    $clientStmt = $pdo->prepare("INSERT INTO clients (name, email, phone, service) VALUES (?, ?, ?, ?)");
                    $clientStmt->execute([$name, $email, $phone, 'car_rental']);
                    $client_id = $pdo->lastInsertId();

                    // Insert booking with slot_id = NULL
                    $bookingStmt = $pdo->prepare("
                        INSERT INTO bookings (client_id, service_type, service_id, slot_id, pickup_date, pickup_time, dropoff_date, dropoff_time, pickup_location, dropoff_location, total_amount, payment_method, payment_status, hours, rental_type)
                        VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $payment_status = 'pending';
                    $bookingStmt->execute([
                        $client_id, 'car_rental', $service_id,
                        $pickup_full, $pickup_time, $dropoff_full, $dropoff_time,
                        $pickup_location, $dropoff_location,
                        $total_amount, $payment_method, $payment_status,
                        $rental_type === 'hourly' ? $duration_hours : null,
                        $rental_type
                    ]);
                    $booking_id = $pdo->lastInsertId();

                    // Fetch vehicle model and prices for payment description and verification
                    $vehicleStmt = $pdo->prepare("SELECT model, price, daily_price FROM vehicles WHERE id = ?");
                    $vehicleStmt->execute([$service_id]);
                    $vehicle = $vehicleStmt->fetch(PDO::FETCH_ASSOC);
                    $vehicle_model = $vehicle['model'];

                    // Verify total_amount
                    if ($rental_type === 'hourly') {
                        $expected_amount = $duration_hours * $vehicle['price'];
                    } else {
                        $expected_amount = $vehicle['daily_price']; // Fixed daily rate
                    }
                    error_log("Booking validation: booking_id=$booking_id, rental_type=$rental_type, hours=$duration_hours, daily_price={$vehicle['daily_price']}, expected_amount=$expected_amount, total_amount=$total_amount", 3, './iconm3/logs/debug.log');
                    if (abs($total_amount - $expected_amount) > 0.01) {
                        throw new Exception('Invalid total amount.');
                    }

                    // Commit transaction
                    $pdo->commit();

                    // Create HitPay Payment Request
                    $paymentData = [
                        'name' => 'Car Rental Service',
                        'email' => $email,
                        'phone' => $phone,
                        'amount' => number_format($total_amount, 2, '.', ''),
                        'currency' => 'SGD',
                        'redirect_url' => "$baseUrl/car-rental.php?booking_id=$booking_id",
                        'webhook' => "$baseUrl/iconm3/hitpay_webhook.php",
                        'reference_number' => (string)$booking_id,
                        'description' => "Car Rental ($rental_type): $vehicle_model | Pickup: $pickup_full at $pickup_location | Drop-off: $dropoff_full at $dropoff_location",
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

                    // Store payment request ID in session for verification
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
        // error_log("Car rental error: " . $e->getMessage(), 3, '/iconm3/logs/php_errors.log');
    }
}

// Handle success redirection from HitPay
if (isset($_GET['booking_id'])) {
    try {
        $booking_id = filter_input(INPUT_GET, 'booking_id', FILTER_SANITIZE_NUMBER_INT);
        if ($booking_id) {
            $stmt = $pdo->prepare("
                SELECT b.*, c.name, c.email, c.phone, v.model
                FROM bookings b
                JOIN clients c ON b.client_id = c.id
                JOIN vehicles v ON b.service_id = v.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $bookingDetails = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($bookingDetails) {
                if ($bookingDetails['payment_status'] === 'completed') {
                    $successMessage = 'Payment successful! Your car rental booking is confirmed.';
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
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>Car Rental - Icon Services</title>
    <meta name="description" content="We provide Car Detailing, Car Rental, Car Servicing, Valet, and Limousine Services in one place.">
    <meta name="keywords" content="singapore car detailing, singapore car rental, singapore car servicing, singapore valet service, singapore limousine service">
    <meta name="author" content="Icon Services">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://iconcarrentalsg.com/car-rental">
    <meta property="og:site_name" content="Icon Services">
    <meta property="og:description" content="We provide Car Detailing, Car Rental, Car Servicing, Valet, and Limousine Services in one place.">
    <meta property="og:keywords" content="singapore car detailing, singapore car rental, singapore car servicing, singapore valet service, singapore limousine service">
    <meta name="google-site-verification" content="CODEHERE">
    <meta name="msvalidate.01" content="CODEHERE">
    <link rel="canonical" href="https://iconcarrentalsg.com/car-rental">
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
    <script async src="https://www.googletagmanager.com/gtag/js?id=IDHERE"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag() {
        dataLayer.push(arguments);
      }
      gtag("js", new Date());
      gtag("config", "IDHERE");
    </script>
    <link rel="icon" href="./img/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="./css/styles.css">
    <style>
        .modal-map-container {
            height: 200px;
            width: 100%;
            margin-bottom: 15px;
        }
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
        .search-container {
            margin-bottom: 20px;
        }
        .location-input {
            position: relative;
        }
        .location-spinner {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            display: none;
        }
        .map-label {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid #333;
            border-radius: 3px;
            padding: 3px 6px;
            font-size: 12px;
            font-weight: bold;
            color: #333;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-xl navbar-light bg-white fixed-top">
        <div class="container">
            <a class="navbar-brand" href="./">
                <img src="./img/icon-car-rental.png" alt="Icon Services Logo" class="logo" style="max-height: 140px;">
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
            <h2 class="text-center fw-bold mb-5 animate__animated animate__fadeIn">Car Rental</h2>
            <p class="lead text-center mb-5">Rent premium vehicles for any occasion.</p>

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
                        <p><strong>Vehicle:</strong> <?php echo htmlspecialchars($bookingDetails['model']); ?></p>
                        <p><strong>Rental Type:</strong> <?php echo htmlspecialchars($bookingDetails['rental_type']); ?></p>
                        <p><strong>Pickup Date & Time:</strong> <?php echo htmlspecialchars($bookingDetails['pickup_date']); ?></p>
                        <p><strong>Pickup Location:</strong> <?php echo htmlspecialchars($bookingDetails['pickup_location'] ?: 'Not specified'); ?></p>
                        <p><strong>Drop-off Date & Time:</strong> <?php echo htmlspecialchars($bookingDetails['dropoff_date']); ?></p>
                        <p><strong>Drop-off Location:</strong> <?php echo htmlspecialchars($bookingDetails['dropoff_location'] ?: 'Not specified'); ?></p>
                        <p><strong>Total Amount:</strong> S$<?php echo number_format($bookingDetails['total_amount'], 2); ?></p>
                        <p><strong>Payment Method:</strong> <?php echo htmlspecialchars($bookingDetails['payment_method']); ?></p>
                        <p><strong>Payment Status:</strong> <?php echo htmlspecialchars($bookingDetails['payment_status']); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="search-container">
                <form method="GET" action="car-rental.php" class="row g-3 align-items-center" id="search-form">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control" placeholder="Search by model or location" value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>
                    <div class="col-md-4 location-input">
                        <input type="text" id="location-input" class="form-control" placeholder="Enter your location" <?php echo $sortBy === 'nearest' ? 'value="' . htmlspecialchars($_GET['address'] ?? '') . '"' : ''; ?>>
                        <div class="location-spinner">
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary me-2">Search</button>
                        <button type="button" id="sort-nearest-btn" class="btn btn-outline-primary me-2">Sort by Nearest</button>
                        <button type="button" id="clear-search-btn" class="btn btn-outline-secondary">Clear Search</button>
                    </div>
                    <?php if ($sortBy === 'nearest'): ?>
                        <input type="hidden" name="sort" value="nearest">
                        <input type="hidden" name="lat" id="user-lat" value="<?php echo htmlspecialchars($userLat ?? ''); ?>">
                        <input type="hidden" name="lng" id="user-lng" value="<?php echo htmlspecialchars($userLng ?? ''); ?>">
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($vehicles)): ?>
                <div class="alert alert-warning text-center">
                    No vehicles found matching your search.
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($vehicles as $vehicle): ?>
                        <div class="col-md-4">
                            <div class="card border-light shadow-sm h-100">
                                <?php if ($vehicle['image']): ?>
                                    <img src="./img/<?php echo htmlspecialchars($vehicle['image']); ?>" alt="<?php echo htmlspecialchars($vehicle['model']); ?>" class="card-img-top">
                                <?php else: ?>
                                    <img src="./img/default-car.jpg" alt="Default Car Image" class="card-img-top">
                                <?php endif; ?>
                                <div class="card-body text-center">
                                    <h3 class="card-title fw-bold mb-3"><?php echo htmlspecialchars($vehicle['model']); ?></h3>
                                    <p class="card-text mb-2"><?php echo htmlspecialchars($vehicle['description'] ?: 'No description available'); ?></p>
                                    <p class="fw-bold text-primary">S$<?php echo number_format($vehicle['price'], 2); ?> / hour</p>
                                    <p class="fw-bold text-primary">S$<?php echo number_format($vehicle['daily_price'], 2); ?> / day</p>
                                    <button class="btn btn-primary w-100 book-service" 
                                        data-service="<?php echo htmlspecialchars($vehicle['model']); ?>" 
                                        data-service-id="<?php echo $vehicle['id']; ?>" 
                                        data-price="<?php echo $vehicle['price']; ?>" 
                                        data-daily-price="<?php echo $vehicle['daily_price']; ?>" 
                                        data-location="<?php echo htmlspecialchars($vehicle['address']); ?>" 
                                        data-lat="<?php echo htmlspecialchars($vehicle['latitude']); ?>" 
                                        data-lng="<?php echo htmlspecialchars($vehicle['longitude']); ?>" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#bookingModal" 
                                        <?php echo $cutOff ? 'disabled' : ''; ?>>Book Now</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Vehicle pagination" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($searchQuery); ?>&sort=<?php echo $sortBy; ?>&lat=<?php echo $userLat; ?>&lng=<?php echo $userLng; ?>&address=<?php echo urlencode($_GET['address'] ?? ''); ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($searchQuery); ?>&sort=<?php echo $sortBy; ?>&lat=<?php echo $userLat; ?>&lng=<?php echo $userLng; ?>&address=<?php echo urlencode($_GET['address'] ?? ''); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($searchQuery); ?>&sort=<?php echo $sortBy; ?>&lat=<?php echo $userLat; ?>&lng=<?php echo $userLng; ?>&address=<?php echo urlencode($_GET['address'] ?? ''); ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($cutOff): ?>
                <p class="text-center text-danger mt-4">Car rental bookings are currently closed.</p>
            <?php endif; ?>
        </section>

        <div class="modal fade" id="bookingModal" tabindex="-1" aria-labelledby="bookingModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="bookingModalLabel">Schedule Your Rental</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <h4 id="selected-service" class="text-center mb-4"></h4>
                        <div class="modal-map-container" id="modal-map"></div>
                        <form id="booking-form" method="POST">
                            <input type="hidden" name="service_type" value="car_rental">
                            <input type="hidden" name="service_id">
                            <input type="hidden" name="total_amount" id="total_amount">
                            <div class="mb-3">
                                <label class="form-label">Rental Type</label>
                                <div>
                                    <input type="radio" id="rental_hourly" name="rental_type" value="hourly" checked>
                                    <label for="rental_hourly">Hourly</label>
                                    <input type="radio" id="rental_daily" name="rental_type" value="daily">
                                    <label for="rental_daily">Daily</label>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="pickup_date" class="form-label">Pickup Date</label>
                                    <input type="date" id="pickup_date" name="pickup_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="pickup_time" class="form-label">Pickup Time</label>
                                    <div id="pickup_time_container">
                                        <select id="pickup_time" name="pickup_time" class="form-control" required disabled>
                                            <option value="">Select a date first</option>
                                        </select>
                                        <div id="pickup-time-loading" class="d-none mt-2">
                                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading slots...
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="dropoff_date" class="form-label">Drop-off Date</label>
                                    <input type="date" id="dropoff_date" name="dropoff_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="dropoff_time" class="form-label">Drop-off Time</label>
                                    <div id="dropoff_time_container">
                                        <select id="dropoff_time" name="dropoff_time" class="form-control" required disabled>
                                            <option value="">Select a pickup time first</option>
                                        </select>
                                        <div id="dropoff-time-loading" class="d-none mt-2">
                                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading slots...
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="pickup_location" class="form-label">Pickup Location</label>
                                    <input type="text" id="pickup_location" name="pickup_location" class="form-control" required readonly>
                                </div>
                                <div class="col-md-6">
                                    <label for="dropoff_location" class="form-label">Drop-off Location</label>
                                    <input type="text" id="dropoff_location" name="dropoff_location" class="form-control" required readonly>
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
                            <div class="mb-3">
                                <p><strong>Total Amount: </strong><span id="total_amount_display">$0.00</span></p>
                            </div>
                            <div id="time-error" class="alert alert-danger d-none mb-3">
                                Hourly rental times must be between 7:00 AM and 11:00 PM. Daily rentals are fixed at 7:00 AM pickup and 11:00 PM drop-off.
                            </div>
                            <div id="duration-error" class="alert alert-danger d-none mb-3">
                                Minimum rental duration is 2 hours for hourly or 1 day for daily rentals.
                            </div>
                            <div id="overlap-error" class="alert alert-danger d-none mb-3">
                                The selected time slot overlaps with an existing booking. Please choose a different time.
                            </div>
                            <div id="no-slots-error" class="alert alert-warning d-none mb-3">
                                No available time slots for this date. Please choose another date.
                            </div>
                            <button type="submit" class="btn btn-primary w-100" id="confirm-booking-btn" name="confirm-booking" disabled>Confirm Booking</button>
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

        <footer class="footer-section">
            <div class="container">
                <div class="row">
                    <div class="col-md-4 mb-4">
                        <a href="./">
                            <img src="./img/icon.png" alt="Icon Services Logo" class="footer-logo mb-3" style="max-width: 150px; max-height: 150px;">
                        </a>
                        <p class="text-white">Icon Services Pte. Ltd.<br>
                        UEN: XXYYZZ123</p>
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
        <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars(GOOGLE_MAPS_API_KEY); ?>&libraries=places,marker"></script>
        <script>
            let selectedServiceId = null;
            let hourlyPrice = 0;
            let dailyPrice = 0;
            let defaultLocation = '';
            let selectedLat = null;
            let selectedLng = null;
            let unavailablePickupSlots = [];
            let unavailableDropoffSlots = [];

            // Generate time slots from 7:00 AM to 11:00 PM with 30-minute intervals for hourly rentals
            const allTimeSlots = [];
            for (let hour = 7; hour <= 22; hour++) {
                allTimeSlots.push(`${hour.toString().padStart(2, '0')}:00`);
                if (hour < 22) {
                    allTimeSlots.push(`${hour.toString().padStart(2, '0')}:30`);
                }
            }

            // Initialize Google Maps in modal
            function initMap(lat, lng, address) {
                const map = new google.maps.Map(document.getElementById('modal-map'), {
                    center: { lat: parseFloat(lat), lng: parseFloat(lng) },
                    zoom: 15
                });
                const marker = new google.maps.Marker({
                    position: { lat: parseFloat(lat), lng: parseFloat(lng) },
                    map: map,
                    title: address
                });
                const infoWindow = new google.maps.InfoWindow({
                    content: `<div class="map-label">${address || 'Unknown Location'}</div>`
                });
                infoWindow.open(map, marker); // Open automatically
            }

            // Initialize location autocomplete
            function initAutocomplete() {
                const locationInput = document.getElementById('location-input');
                const autocomplete = new google.maps.places.Autocomplete(locationInput, {
                    types: ['geocode'],
                    componentRestrictions: { country: 'sg' }
                });
                autocomplete.addListener('place_changed', () => {
                    const place = autocomplete.getPlace();
                    if (place.geometry) {
                        document.getElementById('user-lat').value = place.geometry.location.lat();
                        document.getElementById('user-lng').value = place.geometry.location.lng();
                    }
                });
            }

            // Update time inputs based on rental type
            function updateTimeInputs() {
                const rentalType = document.querySelector('input[name="rental_type"]:checked').value;
                const pickupContainer = document.getElementById('pickup_time_container');
                const dropoffContainer = document.getElementById('dropoff_time_container');
                const pickupDate = document.getElementById('pickup_date').value;
                const dropoffDate = document.getElementById('dropoff_date').value;

                if (rentalType === 'daily') {
                    pickupContainer.innerHTML = `
                        <input type="text" id="pickup_time" name abraz="pickup_time" class="form-control" value="07:00" readonly required>
                        <input type="hidden" name="pickup_time" value="07:00">
                    `;
                    dropoffContainer.innerHTML = `
                        <input type="text" id="dropoff_time" name="dropoff_time" class="form-control" value="23:00" readonly required>
                        <input type="hidden" name="dropoff_time" value="23:00">
                    `;
                    if (pickupDate && dropoffDate) {
                        fetchUnavailableSlots(pickupDate, selectedServiceId, false);
                    }
                } else {
                    pickupContainer.innerHTML = `
                        <select id="pickup_time" name="pickup_time" class="form-control" required ${pickupDate ? '' : 'disabled'}>
                            <option value="">Select a date first</option>
                        </select>
                        <div id="pickup-time-loading" class="d-none mt-2">
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading slots...
                        </div>
                    `;
                    dropoffContainer.innerHTML = `
                        <select id="dropoff_time" name="dropoff_time" class="form-control" required ${dropoffDate ? '' : 'disabled'}>
                            <option value="">Select a pickup time first</option>
                        </select>
                        <div id="dropoff-time-loading" class="d-none mt-2">
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading slots...
                        </div>
                    `;
                    if (pickupDate) {
                        fetchUnavailableSlots(pickupDate, selectedServiceId, false);
                    }
                    if (dropoffDate) {
                        fetchUnavailableSlots(dropoffDate, selectedServiceId, true);
                    }
                }
                calculateTotalAmount();
            }

            // Populate time slots for hourly rentals
            function populateTimeSlots(selectElement, unavailableSlots, isDropoff = false) {
                const rentalType = document.querySelector('input[name="rental_type"]:checked').value;
                if (rentalType === 'daily') return; // Skip for daily rentals

                selectElement.innerHTML = '<option value="">Select a time</option>';
                allTimeSlots.forEach(slot => {
                    if (!unavailableSlots.includes(slot)) {
                        const option = document.createElement('option');
                        option.value = slot;
                        option.text = slot;
                        selectElement.appendChild(option);
                    }
                });
                selectElement.disabled = unavailableSlots.length >= allTimeSlots.length;
                if (selectElement.disabled) {
                    document.getElementById('no-slots-error').classList.remove('d-none');
                } else {
                    document.getElementById('no-slots-error').classList.add('d-none');
                }
            }

            // Fetch unavailable slots
            function fetchUnavailableSlots(date, serviceId, isDropoff = false) {
                const rentalType = document.querySelector('input[name="rental_type"]:checked').value;
                if (!date || !serviceId || rentalType === 'daily') {
                    // For daily rentals, check availability and update UI if needed
                    if (rentalType === 'daily' && date && serviceId) {
                        $.ajax({
                            url: 'iconm3/get_unavailable_slots.php',
                            method: 'POST',
                            data: {
                                date: date,
                                service_id: serviceId,
                                service_type: 'car_rental',
                                rental_type: rentalType
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.error || response.unavailable_slots.length > 0) {
                                    document.getElementById('no-slots-error').classList.remove('d-none');
                                    document.getElementById('confirm-booking-btn').disabled = true;
                                } else {
                                    document.getElementById('no-slots-error').classList.add('d-none');
                                    calculateTotalAmount();
                                }
                            },
                            error: function() {
                                document.getElementById('no-slots-error').classList.remove('d-none');
                                document.getElementById('confirm-booking-btn').disabled = true;
                            }
                        });
                    }
                    return;
                }

                const selectElement = isDropoff ? document.getElementById('dropoff_time') : document.getElementById('pickup_time');
                const loadingElement = isDropoff ? document.getElementById('dropoff-time-loading') : document.getElementById('pickup-time-loading');

                selectElement.disabled = true;
                loadingElement.classList.remove('d-none');
                document.getElementById('no-slots-error').classList.add('d-none');

                $.ajax({
                    url: 'iconm3/get_unavailable_slots.php',
                    method: 'POST',
                    data: {
                        date: date,
                        service_id: serviceId,
                        service_type: 'car_rental',
                        rental_type: rentalType
                    },
                    dataType: 'json',
                    success: function(response) {
                        loadingElement.classList.add('d-none');
                        if (response.error) {
                            document.getElementById('no-slots-error').classList.remove('d-none');
                            selectElement.disabled = true;
                            return;
                        }
                        if (isDropoff) {
                            unavailableDropoffSlots = response.unavailable_slots || [];
                        } else {
                            unavailablePickupSlots = response.unavailable_slots || [];
                        }
                        populateTimeSlots(selectElement, response.unavailable_slots, isDropoff);
                    },
                    error: function() {
                        loadingElement.classList.add('d-none');
                        document.getElementById('no-slots-error').classList.remove('d-none');
                        selectElement.disabled = true;
                    }
                });
            }

            // Calculate total amount
            function calculateTotalAmount() {
                const pickupDate = document.getElementById('pickup_date').value;
                const pickupTime = document.getElementById('pickup_time').value;
                const dropoffDate = document.getElementById('dropoff_date').value;
                const dropoffTime = document.getElementById('dropoff_time').value;
                const rentalType = document.querySelector('input[name="rental_type"]:checked').value;

                if (!pickupDate || !pickupTime || !dropoffDate || !dropoffTime) {
                    document.getElementById('total_amount_display').textContent = '$0.00';
                    document.getElementById('total_amount').value = '';
                    document.getElementById('confirm-booking-btn').disabled = true;
                    document.getElementById('time-error').classList.add('d-none');
                    return;
                }

                const pickupDateTime = new Date(`${pickupDate}T${pickupTime}`);
                const dropoffDateTime = new Date(`${dropoffDate}T${dropoffTime}`);
                const hours = (dropoffDateTime - pickupDateTime) / (1000 * 60 * 60);
                const days = Math.ceil(hours / 24);

                // Validate times
                let isValidTime = true;
                if (rentalType === 'daily') {
                    isValidTime = pickupTime === '07:00' && dropoffTime === '23:00';
                } else {
                    isValidTime = allTimeSlots.includes(pickupTime) && allTimeSlots.includes(dropoffTime);
                }
                document.getElementById('time-error').classList.toggle('d-none', isValidTime);

                let totalAmount = 0;
                let isValidDuration = true;

                if (rentalType === 'hourly') {
                    totalAmount = hours * hourlyPrice;
                    isValidDuration = hours >= 2;
                } else {
                    totalAmount = dailyPrice; // Fixed daily rate
                    isValidDuration = days >= 1;
                }

                console.log(`Calculating total: rentalType=${rentalType}, hours=${hours}, days=${days}, dailyPrice=${dailyPrice}, totalAmount=${totalAmount}`);

                document.getElementById('duration-error').classList.toggle('d-none', isValidDuration);
                document.getElementById('total_amount_display').textContent = `S$${totalAmount.toFixed(2)}`;
                document.getElementById('total_amount').value = totalAmount.toFixed(2);

                // Check for overlaps
                if (isValidDuration && isValidTime) {
                    $.ajax({
                        url: 'iconm3/check_overlap.php',
                        method: 'POST',
                        data: {
                            service_type: 'car_rental',
                            service_id: selectedServiceId,
                            pickup_date: pickupDate,
                            pickup_time: pickupTime,
                            dropoff_date: dropoffDate,
                            dropoff_time: dropoffTime
                        },
                        dataType: 'json',
                        success: function(response) {
                            const hasOverlap = response.has_overlap;
                            document.getElementById('overlap-error').classList.toggle('d-none', !hasOverlap);
                            document.getElementById('confirm-booking-btn').disabled = !isValidDuration || hasOverlap || !isValidTime;
                        },
                        error: function() {
                            document.getElementById('overlap-error').classList.remove('d-none');
                            document.getElementById('confirm-booking-btn').disabled = true;
                        }
                    });
                } else {
                    document.getElementById('confirm-booking-btn').disabled = true;
                }
            }

            // Handle book service button click
            $('.book-service').on('click', function() {
                const service = $(this).data('service');
                selectedServiceId = $(this).data('service-id');
                hourlyPrice = parseFloat($(this).data('price'));
                dailyPrice = parseFloat($(this).data('daily-price'));
                defaultLocation = $(this).data('location');
                selectedLat = $(this).data('lat');
                selectedLng = $(this).data('lng');

                $('#selected-service').text(service);
                $('#booking-form input[name="service_id"]').val(selectedServiceId);
                $('#pickup_location').val(defaultLocation);
                $('#dropoff_location').val(defaultLocation);
                initMap(selectedLat, selectedLng, defaultLocation);

                // Reset form
                $('#pickup_date').val('');
                $('#dropoff_date').val('');
                updateTimeInputs();
                $('#total_amount_display').text('$0.00');
                $('#total_amount').val('');
                $('#confirm-booking-btn').prop('disabled', true);
                $('#time-error').addClass('d-none');
                $('#duration-error').addClass('d-none');
                $('#overlap-error').addClass('d-none');
                $('#no-slots-error').addClass('d-none');
            });

            // Handle rental type change
            $('input[name="rental_type"]').on('change', updateTimeInputs);

            // Handle date changes
            $('#pickup_date').on('change', function() {
                const date = $(this).val();
                $('#dropoff_date').attr('min', date);
                updateTimeInputs();
            });

            $('#dropoff_date').on('change', updateTimeInputs);

            // Handle time changes
            $('#pickup_time, #dropoff_time').on('change', calculateTotalAmount);

            // Handle form submission
            $('#booking-form').on('submit', function() {
                $('#spinner').css('display', 'flex');
            });

            // Handle sort by nearest
            $('#sort-nearest-btn').on('click', function() {
                const lat = $('#user-lat').val();
                const lng = $('#user-lng').val();
                const address = $('#location-input').val();
                if (lat && lng) {
                    $('#search-form').append('<input type="hidden" name="sort" value="nearest">');
                    $('#search-form').append(`<input type="hidden" name="lat" value="${lat}">`);
                    $('#search-form').append(`<input type="hidden" name="lng" value="${lng}">`);
                    $('#search-form').append(`<input type="hidden" name="address" value="${address}">`);
                    $('#search-form').submit();
                } else {
                    alert('Please enter a valid location.');
                }
            });

            // Handle clear search
            $('#clear-search-btn').on('click', function() {
                $('#search-form').find('input[name="search"]').val('');
                $('#search-form').find('input[name="sort"]').remove();
                $('#search-form').find('input[name="lat"]').remove();
                $('#search-form').find('input[name="lng"]').remove();
                $('#search-form').find('input[name="address"]').remove();
                $('#location-input').val('');
                $('#user-lat').val('');
                $('#user-lng').val('');
                $('#search-form').submit();
            });

            // Initialize autocomplete
            initAutocomplete();
        </script>
</body>
</html>