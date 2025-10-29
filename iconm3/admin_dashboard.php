<?php
require 'db.php';
require_once 'utils.php';
session_start();

// Prevent crawlers
header('X-Robots-Tag: noindex, nofollow');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

// Initialize messages
$errorMessage = '';
$successMessage = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

try {
    // Pagination and filtering for bookings
    $bookingsPerPage = 10;
    $bookingsPage = isset($_GET['bookings_page']) ? max(1, (int)$_GET['bookings_page']) : 1;
    $bookingsOffset = ($bookingsPage - 1) * $bookingsPerPage;

    $bookingSearch = isset($_GET['booking_search']) ? trim(filter_input(INPUT_GET, 'booking_search', FILTER_SANITIZE_STRING)) : '';
    $serviceFilter = isset($_GET['service_filter']) ? filter_input(INPUT_GET, 'service_filter', FILTER_SANITIZE_STRING) : '';
    $statusFilter = isset($_GET['status_filter']) ? filter_input(INPUT_GET, 'status_filter', FILTER_SANITIZE_STRING) : '';
    $paymentStatusFilter = isset($_GET['payment_status_filter']) ? filter_input(INPUT_GET, 'payment_status_filter', FILTER_SANITIZE_STRING) : '';
    $bookingSort = isset($_GET['booking_sort']) ? filter_input(INPUT_GET, 'booking_sort', FILTER_SANITIZE_STRING) : 'created_at';
    $bookingOrder = isset($_GET['booking_order']) ? filter_input(INPUT_GET, 'booking_order', FILTER_SANITIZE_STRING) : 'DESC';

    $validBookingSorts = ['created_at', 'total_amount', 'name'];
    $bookingSort = in_array($bookingSort, $validBookingSorts) ? $bookingSort : 'created_at';
    $bookingOrder = strtoupper($bookingOrder) === 'ASC' ? 'ASC' : 'DESC';

    $bookingQuery = "
        SELECT b.id, b.client_id, b.service_type, b.service_id, b.slot_id, b.pickup_date, b.pickup_time, 
            b.dropoff_date, b.dropoff_time, b.pickup_location, b.dropoff_location, b.hours, 
            b.total_amount, b.payment_method, b.payment_status, b.status, b.refund_pending, 
            b.payment_id, b.payment_request_id, b.created_at, b.valet_included,
            c.name, c.email, c.phone, 
            COALESCE(l.model, v.model, vs.name, cs.name) AS service_name
        FROM bookings b
        JOIN clients c ON b.client_id = c.id
        LEFT JOIN limousines l ON b.service_id = l.id AND b.service_type = 'limousine'
        LEFT JOIN vehicles v ON b.service_id = v.id AND b.service_type = 'car_rental'
        LEFT JOIN valet_services vs ON b.service_id = vs.id AND b.service_type = 'valet'
        LEFT JOIN car_services cs ON b.service_id = cs.id AND b.service_type = 'car_servicing'
        WHERE 1=1
    ";
    $bookingParams = [];

    if ($bookingSearch) {
        $bookingQuery .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
        $searchTerm = "%$bookingSearch%";
        $bookingParams[] = $searchTerm;
        $bookingParams[] = $searchTerm;
        $bookingParams[] = $searchTerm;
    }

    if ($serviceFilter) {
        $bookingQuery .= " AND b.service_type = ?";
        $bookingParams[] = $serviceFilter;
    }

    if ($statusFilter) {
        $bookingQuery .= " AND b.status = ?";
        $bookingParams[] = $statusFilter;
    }

    if ($paymentStatusFilter) {
        $bookingQuery .= " AND b.payment_status = ?";
        $bookingParams[] = $paymentStatusFilter;
    }

    $bookingQuery .= " ORDER BY ";
    if ($bookingSort === 'name') {
        $bookingQuery .= "c.name $bookingOrder";
    } else {
        $bookingQuery .= "b.$bookingSort $bookingOrder";
    }

    $bookingQuery .= " LIMIT " . (int)$bookingsPerPage . " OFFSET " . (int)$bookingsOffset;

    $stmt = $pdo->prepare($bookingQuery);
    $stmt->execute($bookingParams);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count total bookings for pagination
    $countQuery = "SELECT COUNT(*) FROM bookings b JOIN clients c ON b.client_id = c.id WHERE 1=1";
    $countParams = [];

    if ($bookingSearch) {
        $countQuery .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
    }

    if ($serviceFilter) {
        $countQuery .= " AND b.service_type = ?";
        $countParams[] = $serviceFilter;
    }

    if ($statusFilter) {
        $countQuery .= " AND b.status = ?";
        $countParams[] = $statusFilter;
    }

    if ($paymentStatusFilter) {
        $countQuery .= " AND b.payment_status = ?";
        $countParams[] = $paymentStatusFilter;
    }

    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($countParams);
    $totalBookings = $countStmt->fetchColumn();
    $totalBookingPages = ceil($totalBookings / $bookingsPerPage);

    // Ensure page number is within valid range
    if ($bookingsPage > $totalBookingPages && $totalBookingPages > 0) {
        $bookingsPage = $totalBookingPages;
        $bookingsOffset = ($bookingsPage - 1) * $bookingsPerPage;
        $bookingQuery = "
            SELECT b.id, b.client_id, b.service_type, b.service_id, b.slot_id, b.pickup_date, b.pickup_time, 
                b.dropoff_date, b.dropoff_time, b.pickup_location, b.dropoff_location, b.hours, 
                b.total_amount, b.payment_method, b.payment_status, b.status, b.refund_pending, 
                b.payment_id, b.payment_request_id, b.created_at, b.valet_included,
                c.name, c.email, c.phone, 
                COALESCE(l.model, v.model, vs.name, cs.name) AS service_name
            FROM bookings b
            JOIN clients c ON b.client_id = c.id
            LEFT JOIN limousines l ON b.service_id = l.id AND b.service_type = 'limousine'
            LEFT JOIN vehicles v ON b.service_id = v.id AND b.service_type = 'car_rental'
            LEFT JOIN valet_services vs ON b.service_id = vs.id AND b.service_type = 'valet'
            LEFT JOIN car_services cs ON b.service_id = cs.id AND b.service_type = 'car_servicing'
            WHERE 1=1
        ";
        $bookingParams = [];

        if ($bookingSearch) {
            $bookingQuery .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
            $bookingParams[] = $searchTerm;
            $bookingParams[] = $searchTerm;
            $bookingParams[] = $searchTerm;
        }

        if ($serviceFilter) {
            $bookingQuery .= " AND b.service_type = ?";
            $bookingParams[] = $serviceFilter;
        }

        if ($statusFilter) {
            $bookingQuery .= " AND b.status = ?";
            $bookingParams[] = $statusFilter;
        }

        if ($paymentStatusFilter) {
            $bookingQuery .= " AND b.payment_status = ?";
            $bookingParams[] = $paymentStatusFilter;
        }

        $bookingQuery .= " ORDER BY ";
        if ($bookingSort === 'name') {
            $bookingQuery .= "c.name $bookingOrder";
        } else {
            $bookingQuery .= "b.$bookingSort $bookingOrder";
        }

        $bookingQuery .= " LIMIT " . (int)$bookingsPerPage . " OFFSET " . (int)$bookingsOffset;

        $stmt = $pdo->prepare($bookingQuery);
        $stmt->execute($bookingParams);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Pagination and filtering for clients
    $clientsPerPage = 10;
    $clientsPage = isset($_GET['clients_page']) ? max(1, (int)$_GET['clients_page']) : 1;
    $clientsOffset = ($clientsPage - 1) * $clientsPerPage;

    $clientSearch = isset($_GET['client_search']) ? trim(filter_input(INPUT_GET, 'client_search', FILTER_SANITIZE_STRING)) : '';
    $clientStatusFilter = isset($_GET['client_status_filter']) ? filter_input(INPUT_GET, 'client_status_filter', FILTER_SANITIZE_STRING) : '';
    $clientSort = isset($_GET['client_sort']) ? filter_input(INPUT_GET, 'client_sort', FILTER_SANITIZE_STRING) : 'last_booking';
    $clientOrder = isset($_GET['client_order']) ? filter_input(INPUT_GET, 'client_order', FILTER_SANITIZE_STRING) : 'DESC';

    $validClientSorts = ['last_booking', 'name'];
    $clientSort = in_array($clientSort, $validClientSorts) ? $clientSort : 'last_booking';
    $clientOrder = strtoupper($clientOrder) === 'ASC' ? 'ASC' : 'DESC';

    $oneMonthAgo = date('Y-m-d H:i:s', strtotime('-1 month'));
    $clientQuery = "
        SELECT c.*, MAX(b.created_at) as last_booking
        FROM clients c
        LEFT JOIN bookings b ON c.id = b.client_id
        WHERE 1=1
    ";
    $clientParams = [];

    if ($clientSearch) {
        $clientQuery .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
        $searchTerm = "%$clientSearch%";
        $clientParams[] = $searchTerm;
        $clientParams[] = $searchTerm;
        $clientParams[] = $searchTerm;
    }

    if ($clientStatusFilter) {
        if ($clientStatusFilter === 'active') {
            $clientQuery .= " AND (b.created_at IS NOT NULL AND b.created_at > ?)";
            $clientParams[] = $oneMonthAgo;
        } elseif ($clientStatusFilter === 'inactive') {
            $clientQuery .= " AND (b.created_at IS NULL OR b.created_at <= ?)";
            $clientParams[] = $oneMonthAgo;
        }
    }

    $clientQuery .= " GROUP BY c.id ORDER BY $clientSort $clientOrder";
    $clientQuery .= " LIMIT " . (int)$clientsPerPage . " OFFSET " . (int)$clientsOffset;

    $stmt = $pdo->prepare($clientQuery);
    $stmt->execute($clientParams);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count total clients for pagination
    $countQuery = "SELECT COUNT(DISTINCT c.id) FROM clients c LEFT JOIN bookings b ON c.id = b.client_id WHERE 1=1";
    $countParams = [];

    if ($clientSearch) {
        $countQuery .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
    }

    if ($clientStatusFilter) {
        if ($clientStatusFilter === 'active') {
            $countQuery .= " AND (b.created_at IS NOT NULL AND b.created_at > ?)";
            $countParams[] = $oneMonthAgo;
        } elseif ($clientStatusFilter === 'inactive') {
            $countQuery .= " AND (b.created_at IS NULL OR b.created_at <= ?)";
            $countParams[] = $oneMonthAgo;
        }
    }

    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($countParams);
    $totalClients = $countStmt->fetchColumn();
    $totalClientPages = ceil($totalClients / $clientsPerPage);

    // Ensure page number is within valid range
    if ($clientsPage > $totalClientPages && $totalClientPages > 0) {
        $clientsPage = $totalClientPages;
        $clientsOffset = ($clientsPage - 1) * $clientsPerPage;
        $clientQuery = "
            SELECT c.*, MAX(b.created_at) as last_booking
            FROM clients c
            LEFT JOIN bookings b ON c.id = b.client_id
            WHERE 1=1
        ";
        $clientParams = [];

        if ($clientSearch) {
            $clientQuery .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
            $clientParams[] = $searchTerm;
            $clientParams[] = $searchTerm;
            $clientParams[] = $searchTerm;
        }

        if ($clientStatusFilter) {
            if ($clientStatusFilter === 'active') {
                $clientQuery .= " AND (b.created_at IS NOT NULL AND b.created_at > ?)";
                $clientParams[] = $oneMonthAgo;
            } elseif ($clientStatusFilter === 'inactive') {
                $clientQuery .= " AND (b.created_at IS NULL OR b.created_at <= ?)";
                $clientParams[] = $oneMonthAgo;
            }
        }

        $clientQuery .= " GROUP BY c.id ORDER BY $clientSort $clientOrder";
        $clientQuery .= " LIMIT " . (int)$clientsPerPage . " OFFSET " . (int)$clientsOffset;

        $stmt = $pdo->prepare($clientQuery);
        $stmt->execute($clientParams);
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Pagination and filtering for audit logs
    $logsPerPage = 10;
    $logsPage = isset($_GET['logs_page']) ? max(1, (int)$_GET['logs_page']) : 1;
    $logsOffset = ($logsPage - 1) * $logsPerPage;

    $logsQuery = "
        SELECT al.*, a.username AS admin_username
        FROM audit_logs al
        LEFT JOIN admins a ON al.admin_id = a.id
        ORDER BY al.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($logsQuery);
    $stmt->bindValue(':limit', (int)$logsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$logsOffset, PDO::PARAM_INT);
    $stmt->execute();
    $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count total audit logs for pagination
    $countStmt = $pdo->query("SELECT COUNT(*) FROM audit_logs");
    $totalLogs = $countStmt->fetchColumn();
    $totalLogPages = ceil($totalLogs / $logsPerPage);

    // Ensure logs page number is within valid range
    if ($logsPage > $totalLogPages && $totalLogPages > 0) {
        $logsPage = $totalLogPages;
        $logsOffset = ($logsPage - 1) * $logsPerPage;
        $logsQuery = "
            SELECT al.*, a.username AS admin_username
            FROM audit_logs al
            LEFT JOIN admins a ON al.admin_id = a.id
            ORDER BY al.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($logsQuery);
        $stmt->bindValue(':limit', (int)$logsPerPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$logsOffset, PDO::PARAM_INT);
        $stmt->execute();
        $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Handle service status update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_service_status'])) {
        $booking_id = filter_input(INPUT_POST, 'booking_id', FILTER_SANITIZE_NUMBER_INT);
        $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);

        if (!$booking_id || $booking_id <= 0) {
            $errorMessage = 'Invalid booking ID.';
            logAuditAction($pdo, "Admin attempted to update status with invalid booking ID", $booking_id, $_SESSION['admin_id'] ?? null);
        } elseif (!in_array($new_status, ['pending', 'confirmed', 'completed', 'cancelled'])) {
            $errorMessage = 'Invalid service status.';
            logAuditAction($pdo, "Admin attempted to update service status to invalid value: $new_status", $booking_id, $_SESSION['admin_id'] ?? null);
        } else {
            try {
                // Check if booking exists and fetch slot_id
                $stmt = $pdo->prepare("SELECT service_type, service_id, slot_id FROM bookings WHERE id = ?");
                $stmt->execute([$booking_id]);
                $booking = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$booking) {
                    $errorMessage = 'Booking not found.';
                    logAuditAction($pdo, "Admin failed to update service status: Booking not found", $booking_id, $_SESSION['admin_id'] ?? null);
                } else {
                    // Update status
                    $stmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?");
                    $stmt->execute([$new_status, $booking_id]);
                    if ($stmt->rowCount() > 0) {
                        logAuditAction($pdo, "Admin updated service status to $new_status", $booking_id, $_SESSION['admin_id'] ?? null);

                        // Release resources if status is cancelled
                        if ($new_status === 'cancelled') {
                            releaseResources($pdo, $booking_id, $booking['service_type'], $booking['service_id'], $booking['slot_id']);
                            // Audit log for resource release is handled in releaseResources
                        }
                        // Send Telegram notification if status is completed
                        elseif ($new_status === 'completed') {
                            if (!sendTelegramNotification($pdo, $booking_id, 'completion')) {
                                $errorMessage = 'Status updated, but failed to send Telegram notification.';
                            }
                        }

                        $_SESSION['success_message'] = 'Service status updated successfully.';
                    } else {
                        $errorMessage = 'Booking status unchanged.';
                        logAuditAction($pdo, "Admin failed to update service status: No changes applied", $booking_id, $_SESSION['admin_id'] ?? null);
                    }
                }
            } catch (PDOException $e) {
                $errorMessage = 'Database error during status update.';
                logAuditAction($pdo, "Admin failed to update service status: Database error - " . $e->getMessage(), $booking_id, $_SESSION['admin_id'] ?? null);
            }
        }

        header('Location: admin_dashboard.php?' . http_build_query([
            'bookings_page' => $bookingsPage,
            'booking_search' => $bookingSearch,
            'service_filter' => $serviceFilter,
            'status_filter' => $statusFilter,
            'payment_status_filter' => $paymentStatusFilter,
            'booking_sort' => $bookingSort,
            'booking_order' => $bookingOrder,
            'clients_page' => $clientsPage,
            'client_search' => $clientSearch,
            'client_status_filter' => $clientStatusFilter,
            'client_sort' => $clientSort,
            'client_order' => $clientOrder,
            'logs_page' => $logsPage
        ]));
        exit;
    }

    // Handle manual payment confirmation
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
        $booking_id = filter_input(INPUT_POST, 'booking_id', FILTER_SANITIZE_NUMBER_INT);
        if ($booking_id) {
            $stmt = $pdo->prepare("SELECT payment_method, status FROM bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch();

            if ($booking) {
                $pdo->beginTransaction();
                try {
                    // Update payment status to completed
                    $stmt = $pdo->prepare("UPDATE bookings SET payment_status = 'completed' WHERE id = ? AND payment_status = 'pending'");
                    $updated = $stmt->execute([$booking_id]);
                    if ($updated && $stmt->rowCount() > 0) {
                        // Update booking status to confirmed if not already completed or cancelled
                        if ($booking['status'] !== 'completed' && $booking['status'] !== 'cancelled') {
                            $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
                            $stmt->execute([$booking_id]);
                            logAuditAction($pdo, "Admin updated service status to confirmed due to payment confirmation", $booking_id, $_SESSION['admin_id'] ?? null);
                        }

                        // Clear revenue cache
                        $cacheFile = './cache/total_revenue.txt';
                        if (file_exists($cacheFile)) {
                            if (!unlink($cacheFile)) {
                                $errorMessage = 'Failed to clear revenue cache. Revenue may be outdated.';
                            }
                        }

                        // Log payment confirmation
                        logAuditAction($pdo, "Admin manually confirmed payment", $booking_id, $_SESSION['admin_id'] ?? null);

                        // Send Telegram notification
                        if (!sendTelegramNotification($pdo, $booking_id)) {
                            $errorMessage = $errorMessage ? $errorMessage . ' Also failed to send Telegram notification.' : 'Failed to send Telegram notification.';
                        }

                        $_SESSION['success_message'] = 'Payment confirmed successfully. Booking status updated to confirmed.';
                        $pdo->commit();
                    } else {
                        $pdo->rollBack();
                        $errorMessage = 'Failed to confirm payment. It may already be confirmed.';
                    }
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $errorMessage = 'Database error during payment confirmation: ' . $e->getMessage();
                }
            } else {
                $errorMessage = 'Booking not found.';
            }
        } else {
            $errorMessage = 'Invalid booking ID for payment confirmation.';
        }
        header('Location: admin_dashboard.php?' . http_build_query([
            'bookings_page' => $bookingsPage,
            'booking_search' => $bookingSearch,
            'service_filter' => $serviceFilter,
            'status_filter' => $statusFilter,
            'payment_status_filter' => $paymentStatusFilter,
            'booking_sort' => $bookingSort,
            'booking_order' => $bookingOrder,
            'clients_page' => $clientsPage,
            'client_search' => $clientSearch,
            'client_status_filter' => $clientStatusFilter,
            'client_sort' => $clientSort,
            'client_order' => $clientOrder,
            'logs_page' => $logsPage
        ]));
        exit;
    }

    // Handle payment refund via HitPay API
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refund_payment'])) {
        $booking_id = filter_input(INPUT_POST, 'booking_id', FILTER_SANITIZE_NUMBER_INT);
        if (!$booking_id) {
            $errorMessage = 'Invalid booking ID for refund.';
            logAuditAction($pdo, "Admin attempted refund but failed: Invalid booking ID", $booking_id, $_SESSION['admin_id'] ?? null);
        } else {
            $stmt = $pdo->prepare("SELECT payment_request_id, total_amount, payment_id, service_type, service_id, slot_id, status, payment_status, refund_pending FROM bookings WHERE id = ? AND payment_status = 'completed' AND refund_pending = 0");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$booking || !($booking['payment_request_id'] || $booking['payment_id'])) {
                $errorMessage = 'Booking not found, not eligible for refund, or refund already pending.';
                logAuditAction($pdo, "Admin attempted refund but failed: Booking not found or not eligible", $booking_id, $_SESSION['admin_id'] ?? null);
            } else {
                // Use payment_id for refund, fall back to payment_request_id
                $hitpayPaymentId = $booking['payment_id'] ?? $booking['payment_request_id'];
                $refundData = [
                    'payment_id' => $hitpayPaymentId,
                    'amount' => number_format($booking['total_amount'], 2, '.', ''),
                    'reason' => 'Admin-initiated refund',
                    'send_email' => 'true',
                ];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, HITPAY_REFUND_API_URL);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($refundData));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'X-BUSINESS-API-KEY: ' . HITPAY_API_KEY,
                    'X-REQUESTED-WITH: XMLHttpRequest',
                    'Content-Type: application/x-www-form-urlencoded'
                ]);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);

                $response = curl_exec($ch);
                if (curl_errno($ch)) {
                    $curlError = curl_error($ch);
                    curl_close($ch);
                    $errorMessage = 'Refund error: Payment gateway connection error: ' . $curlError . '. Please try again or contact support.';
                    logAuditAction($pdo, "Admin attempted refund but failed: $curlError", $booking_id, $_SESSION['admin_id'] ?? null);
                } else {
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $responseData = json_decode($response, true);
                    if ($responseData && isset($responseData['status']) && $responseData['status'] === 'succeeded') {
                        // Mark refund as pending
                        $pdo->beginTransaction();
                        try {
                            $stmt = $pdo->prepare("UPDATE bookings SET refund_pending = 1 WHERE id = ? AND payment_status = 'completed' AND refund_pending = 0");
                            $stmt->execute([$booking_id]);
                            $updatedRows = $stmt->rowCount();
                            if ($updatedRows === 0) {
                                $pdo->rollBack();
                                $errorMessage = 'Failed to queue refund: Booking may already be refunded or pending.';
                                logAuditAction($pdo, "Admin attempted refund but failed: No rows updated", $booking_id, $_SESSION['admin_id'] ?? null);
                            } else {
                                logAuditAction($pdo, "Admin initiated refund, awaiting webhook confirmation", $booking_id, $_SESSION['admin_id'] ?? null);
                                $_SESSION['success_message'] = 'Refund initiated. Awaiting confirmation from payment gateway.';
                                $pdo->commit();
                            }
                        } catch (PDOException $e) {
                            $pdo->rollBack();
                            $errorMessage = 'Error queuing refund: ' . $e->getMessage();
                            logAuditAction($pdo, "Admin attempted refund but failed: " . $e->getMessage(), $booking_id, $_SESSION['admin_id'] ?? null);
                        }
                    } else {
                        $errorDetail = $responseData['message'] ?? ($responseData['error'] ?? 'Unknown error');
                        $errorMessage = 'Failed to process refund: ' . $errorDetail . '. Please try again or contact support.';
                        logAuditAction($pdo, "Admin attempted refund but failed: $errorDetail", $booking_id, $_SESSION['admin_id'] ?? null);
                    }
                }
            }
        }
        header('Location: admin_dashboard.php?' . http_build_query([
            'bookings_page' => $bookingsPage,
            'booking_search' => $bookingSearch,
            'service_filter' => $serviceFilter,
            'status_filter' => $statusFilter,
            'payment_status_filter' => $paymentStatusFilter,
            'booking_sort' => $bookingSort,
            'booking_order' => $bookingOrder,
            'clients_page' => $clientsPage,
            'client_search' => $clientSearch,
            'client_status_filter' => $clientStatusFilter,
            'client_sort' => $clientSort,
            'client_order' => $clientOrder,
            'logs_page' => $logsPage
        ]));
        exit;
    }

    // Handle retry payment for failed bookings
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retry_payment'])) {
        $booking_id = filter_input(INPUT_POST, 'booking_id', FILTER_SANITIZE_NUMBER_INT);
        if ($booking_id) {
            $stmt = $pdo->prepare("
                SELECT b.*, c.name, c.email, c.phone,
                       COALESCE(vs.name, cs.name) AS service_name
                FROM bookings b
                JOIN clients c ON b.client_id = c.id
                LEFT JOIN valet_services vs ON b.service_id = vs.id AND b.service_type = 'valet'
                LEFT JOIN car_services cs ON b.service_id = cs.id AND b.service_type = 'car_servicing'
                WHERE b.id = ? AND b.payment_status = 'failed'
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch();

            if ($booking) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $baseUrl = "$protocol://$host/icon-staging";

                $description = $booking['pickup_date'] ? 
                    "Retry Payment: {$booking['service_type']} - {$booking['service_name']}" . 
                    ($booking['valet_included'] ? ' with Valet' : '') . 
                    " | Pickup: {$booking['pickup_date']} {$booking['pickup_time']}" : 
                    "Retry Payment: Booking ID {$booking_id}";
                $paymentData = [
                    'name' => ucfirst($booking['service_type']) . ' Service',
                    'email' => $booking['email'],
                    'phone' => $booking['phone'],
                    'amount' => number_format($booking['total_amount'], 2, '.', ''),
                    'currency' => 'SGD',
                    'redirect_url' => "$baseUrl/{$booking['service_type']}.php?booking_id=$booking_id",
                    'webhook' => "$baseUrl/iconm3/hitpay_webhook.php",
                    'reference_number' => (string)$booking_id,
                    'description' => $description,
                    'payment_methods' => [$booking['payment_method'] === 'paynow' ? 'paynow_online' : 'card']
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
                    $errorMessage = 'Retry payment error: Payment gateway connection error: ' . $curlError . '. Please try again later or contact support.';
                } else {
                    curl_close($ch);
                    $responseData = json_decode($response, true);
                    if ($responseData && isset($responseData['url'])) {
                        $stmt = $pdo->prepare("UPDATE bookings SET payment_request_id = ?, payment_status = 'pending' WHERE id = ?");
                        $stmt->execute([$responseData['id'], $booking_id]);
                        logAuditAction($pdo, "Admin initiated payment retry", $booking_id, $_SESSION['admin_id'] ?? null);
                        $_SESSION['success_message'] = 'Payment retry initiated. Redirecting to payment page...';
                        header('Location: ' . $responseData['url']);
                        exit;
                    } else {
                        $errorMessage = 'Failed to initiate payment retry: ' . ($responseData['message'] ?? 'Unknown error') . '. Please try again or contact support.';
                    }
                }
            } else {
                $errorMessage = 'Booking not found or not eligible for retry.';
            }
        } else {
            $errorMessage = 'Invalid booking ID for retry.';
        }
        header('Location: admin_dashboard.php?' . http_build_query([
            'bookings_page' => $bookingsPage,
            'booking_search' => $bookingSearch,
            'service_filter' => $serviceFilter,
            'status_filter' => $statusFilter,
            'payment_status_filter' => $paymentStatusFilter,
            'booking_sort' => $bookingSort,
            'booking_order' => $bookingOrder,
            'clients_page' => $clientsPage,
            'client_search' => $clientSearch,
            'client_status_filter' => $clientStatusFilter,
            'client_sort' => $clientSort,
            'client_order' => $clientOrder,
            'logs_page' => $logsPage
        ]));
        exit;
    }

    // Handle service cut-off toggles
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_cutoff'])) {
        $service = filter_input(INPUT_POST, 'service', FILTER_SANITIZE_STRING);
        $value = $_POST['value'] === '1' ? '0' : '1';
        if ($service) {
            $stmt = $pdo->prepare("INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?");
            $stmt->execute(["{$service}_cutoff", $value, $value]);
            logAuditAction($pdo, "Admin toggled $service cut-off to " . ($value == '1' ? 'off' : 'on'), 0, $_SESSION['admin_id'] ?? null);
            $_SESSION['success_message'] = 'Service cut-off updated successfully.';
        } else {
            $errorMessage = 'Invalid service for cut-off toggle.';
        }
        header('Location: admin_dashboard.php?' . http_build_query([
            'bookings_page' => $bookingsPage,
            'booking_search' => $bookingSearch,
            'service_filter' => $serviceFilter,
            'status_filter' => $statusFilter,
            'payment_status_filter' => $paymentStatusFilter,
            'booking_sort' => $bookingSort,
            'booking_order' => $bookingOrder,
            'clients_page' => $clientsPage,
            'client_search' => $clientSearch,
            'client_status_filter' => $clientStatusFilter,
            'client_sort' => $clientSort,
            'client_order' => $clientOrder,
            'logs_page' => $logsPage
        ]));
        exit;
    }

    // Handle report generation
    if (isset($_GET['generate_report'])) {
        $period = filter_input(INPUT_GET, 'generate_report', FILTER_SANITIZE_STRING);
        $startDate = null;
        $endDate = date('Y-m-d H:i:s');

        switch ($period) {
            case 'monthly':
                $startDate = date('Y-m-d H:i:s', strtotime('-1 month'));
                $filename = "monthly_report_" . date('Ymd') . ".csv";
                break;
            case 'half_yearly':
                $startDate = date('Y-m-d H:i:s', strtotime('-6 months'));
                $filename = "half_yearly_report_" . date('Ymd') . ".csv";
                break;
            case 'yearly':
                $startDate = date('Y-m-d H:i:s', strtotime('-1 year'));
                $filename = "yearly_report_" . date('Ymd') . ".csv";
                break;
            default:
                header('Location: admin_dashboard.php');
                exit;
        }

        $stmt = $pdo->prepare("
            SELECT b.id, b.client_id, b.service_type, b.service_id, b.slot_id, b.pickup_date, b.pickup_time, 
                b.dropoff_date, b.dropoff_time, b.pickup_location, b.dropoff_location, b.hours, 
                b.total_amount, b.payment_method, b.payment_status, b.status, b.refund_pending, 
                b.payment_id, b.payment_request_id, b.created_at, b.valet_included,
                c.name, c.email, c.phone,
                COALESCE(l.model, v.model, vs.name, cs.name) AS service_name
            FROM bookings b
            JOIN clients c ON b.client_id = c.id
            LEFT JOIN limousines l ON b.service_id = l.id AND b.service_type = 'limousine'
            LEFT JOIN vehicles v ON b.service_id = v.id AND b.service_type = 'car_rental'
            LEFT JOIN valet_services vs ON b.service_id = vs.id AND b.service_type = 'valet'
            LEFT JOIN car_services cs ON b.service_id = cs.id AND b.service_type = 'car_servicing'
            WHERE b.created_at BETWEEN ? AND ?
            ORDER BY b.created_at DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $reportBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'ID', 'Client Name', 'Email', 'Phone', 'Service Type', 'Service Name', 'Valet Included',
            'Pickup Date & Time', 'Dropoff Date & Time', 'Pickup Location', 'Dropoff Location', 
            'Hours', 'Amount (S$)', 'Payment Method', 'Payment Status', 'Status', 'Refund Pending', 'Created At'
        ]);

        foreach ($reportBookings as $booking) {
            $paymentMethodDisplay = $booking['payment_method'] ?? 'N/A';
            if ($paymentMethodDisplay === 'card') $paymentMethodDisplay = 'Credit/Debit Card';
            elseif ($paymentMethodDisplay === 'paynow') $paymentMethodDisplay = 'PayNow';
            $pickupDateTime = $booking['pickup_date'] ? ($booking['pickup_date'] . ' ' . $booking['pickup_time']) : 'N/A';
            $dropoffDateTime = ($booking['dropoff_date'] && $booking['dropoff_time']) ? 
                $booking['dropoff_date'] . ' ' . $booking['dropoff_time'] : 'N/A';
            fputcsv($output, [
                $booking['id'],
                $booking['name'],
                $booking['email'],
                $booking['phone'],
                $booking['service_type'],
                $booking['service_name'] ?? 'N/A',
                $booking['service_type'] === 'car_servicing' ? ($booking['valet_included'] ? 'Yes' : 'No') : 'N/A',
                $pickupDateTime,
                $dropoffDateTime,
                $booking['pickup_location'] ?? 'N/A',
                $booking['dropoff_location'] ?? 'N/A',
                $booking['hours'] ?? 'N/A',
                number_format($booking['total_amount'], 2),
                $paymentMethodDisplay,
                $booking['payment_status'],
                $booking['status'],
                $booking['refund_pending'] ? 'Yes' : 'No',
                $booking['created_at']
            ]);
        }

        fclose($output);
        exit;
    }

    // Fetch cut-off statuses
    $carDetailingCutOff = $pdo->query("SELECT value FROM settings WHERE name = 'car_detailing_cutoff'")->fetchColumn() == '1';
    $carRentalCutOff = $pdo->query("SELECT value FROM settings WHERE name = 'car_rental_cutoff'")->fetchColumn() == '1';
    $valetCutOff = $pdo->query("SELECT value FROM settings WHERE name = 'valet_cutoff'")->fetchColumn() == '1';
    $limousineCutOff = $pdo->query("SELECT value FROM settings WHERE name = 'limousine_cutoff'")->fetchColumn() == '1';
    $carServicingCutOff = $pdo->query("SELECT value FROM settings WHERE name = 'car_servicing_cutoff'")->fetchColumn() == '1';

    // Fetch total revenue (with caching, excluding refunded bookings)
    $cacheDir = './cache';
    $cacheFile = $cacheDir . '/total_revenue.txt';
    $cacheTime = 300; // Cache for 5 minutes

    // Ensure cache directory exists
    if (!is_dir($cacheDir)) {
        if (!mkdir($cacheDir, 0755, true)) {
            $errorMessage = 'Failed to create cache directory. Caching disabled.';
            $totalRevenue = 0;
            $revenueStmt = $pdo->query("SELECT SUM(total_amount) as total FROM bookings WHERE payment_status = 'completed' AND refund_pending = 0");
            $totalRevenue = $revenueStmt->fetchColumn() ?: 0;
        }
    }

    if (!$errorMessage) {
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
            $totalRevenue = (float)file_get_contents($cacheFile);
        } else {
            $revenueStmt = $pdo->query("SELECT SUM(total_amount) as total FROM bookings WHERE payment_status = 'completed' AND refund_pending = 0");
            $totalRevenue = $revenueStmt->fetchColumn() ?: 0;
            if (!file_put_contents($cacheFile, $totalRevenue)) {
                $errorMessage = 'Failed to write to cache file. Caching disabled.';
            }
        }
    }
} catch (PDOException $e) {
    $errorMessage = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Dashboard - Icon Detailing Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="./css/admin-styles.css">
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
        .payment-status-pending {
            color: #ff9800;
            font-weight: bold;
        }
        .payment-status-completed {
            color: #28a745;
            font-weight: bold;
        }
        .payment-status-failed {
            color: #dc3545;
            font-weight: bold;
        }
        .payment-status-refunded {
            color: #6c757d;
            font-weight: bold;
        }
        .card-body {
            overflow-x: auto;
        }
        .table th, .table td {
            white-space: nowrap;
            padding: 0.75rem;
        }
        .table-responsive {
            margin-bottom: 0;
        }
        .status-dropdown {
            min-width: 120px;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-md navbar-light bg-dark fixed-top">
        <div class="container">
            <a class="navbar-brand" href="admin_dashboard.php">Admin Dashboard</a>
            <button class="navbar-toggler bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="admin_dashboard.php#bookings">Bookings</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin_dashboard.php#clients">Clients</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin_dashboard.php#accounting">Accounting</a></li>
                    <li class="nav-item"><a class="nav-link" href="manage_vehicles.php">Vehicles</a></li>
                    <li class="nav-item"><a class="nav-link" href="manage_limousines.php">Limousines</a></li>
                    <li class="nav-item"><a class="nav-link" href="manage_slots.php">Slots</a></li>
                    <li class="nav-item"><a class="nav-link" href="manage_admins.php">Admins</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin_logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
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

        <!-- Bookings Section -->
        <section id="bookings" class="mb-5" style="margin-top: 6rem;">
            <h2 class="fw-bold mb-4">Manage Bookings</h2>
            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <input type="text" name="booking_search" class="form-control" placeholder="Search by name, email, or phone" value="<?php echo htmlspecialchars($bookingSearch); ?>">
                        </div>
                        <div class="col-md-2">
                            <select name="service_filter" class="form-control">
                                <option value="">All Services</option>
                                <option value="car_detailing" <?php echo $serviceFilter === 'car_detailing' ? 'selected' : ''; ?>>Car Detailing</option>
                                <option value="car_rental" <?php echo $serviceFilter === 'car_rental' ? 'selected' : ''; ?>>Car Rental</option>
                                <option value="valet" <?php echo $serviceFilter === 'valet' ? 'selected' : ''; ?>>Valet</option>
                                <option value="limousine" <?php echo $serviceFilter === 'limousine' ? 'selected' : ''; ?>>Limousine</option>
                                <option value="car_servicing" <?php echo $serviceFilter === 'car_servicing' ? 'selected' : ''; ?>>Car Servicing</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="status_filter" class="form-control">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="confirmed" <?php echo $statusFilter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="payment_status_filter" class="form-control">
                                <option value="">All Payment Statuses</option>
                                <option value="pending" <?php echo $paymentStatusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="completed" <?php echo $paymentStatusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="failed" <?php echo $paymentStatusFilter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                <option value="refunded" <?php echo $paymentStatusFilter === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="admin_dashboard.php" class="btn btn-secondary">Clear</a>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Booking Overview</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($bookings)): ?>
                        <p>No bookings found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>
                                            <a href="admin_dashboard.php?<?php echo http_build_query(array_merge($_GET, ['booking_sort' => 'name', 'booking_order' => $bookingSort === 'name' && $bookingOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>">Client</a>
                                        </th>
                                        <th>Service Type</th>
                                        <th>Service Name</th>
                                        <th>Valet Included</th>
                                        <th>Pickup Date & Time</th>
                                        <th>Dropoff Date & Time</th>
                                        <th>Pickup Location</th>
                                        <th>Dropoff Location</th>
                                        <th>Hours</th>
                                        <th>
                                            <a href="admin_dashboard.php?<?php echo http_build_query(array_merge($_GET, ['booking_sort' => 'total_amount', 'booking_order' => $bookingSort === 'total_amount' && $bookingOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>">Amount (S$)</a>
                                        </th>
                                        <th>Payment Method</th>
                                        <th>Payment Status</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bookings as $booking): 
                                        $paymentMethodDisplay = $booking['payment_method'] ?? 'N/A';
                                        if ($paymentMethodDisplay === 'card') $paymentMethodDisplay = 'Credit/Debit Card';
                                        elseif ($paymentMethodDisplay === 'paynow') $paymentMethodDisplay = 'PayNow';
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($booking['id']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($booking['name']); ?><br>
                                                <?php echo htmlspecialchars($booking['email']); ?><br>
                                                <?php echo htmlspecialchars($booking['phone']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($booking['service_type']); ?></td>
                                            <td><?php echo htmlspecialchars($booking['service_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo $booking['service_type'] === 'car_servicing' ? ($booking['valet_included'] ? 'Yes' : 'No') : 'N/A'; ?></td>
                                            <td><?php echo $booking['pickup_date'] ? htmlspecialchars($booking['pickup_date'] . ' ' . $booking['pickup_time']) : 'N/A'; ?></td>
                                            <td>
                                                <?php 
                                                if ($booking['dropoff_date'] && $booking['dropoff_time']) {
                                                    echo htmlspecialchars($booking['dropoff_date'] . ' ' . $booking['dropoff_time']);
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($booking['pickup_location'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($booking['dropoff_location'] ?? 'N/A'); ?></td>
                                            <td><?php echo $booking['hours'] ? htmlspecialchars($booking['hours']) : 'N/A'; ?></td>
                                            <td><?php echo number_format($booking['total_amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($paymentMethodDisplay); ?></td>
                                            <td class="payment-status-<?php echo $booking['refund_pending'] == 1 ? 'pending' : htmlspecialchars($booking['payment_status']); ?>">
                                                <?php
                                                if ($booking['refund_pending'] == 1) {
                                                    echo 'Pending';
                                                } else {
                                                    echo htmlspecialchars($booking['payment_status']);
                                                    if ($booking['payment_status'] === 'pending') {
                                                        echo '<br><small>(Awaiting HitPay Webhook)</small>';
                                                    }
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <select name="status" class="form-control form-control-sm status-dropdown" onchange="this.form.submit()">
                                                        <option value="pending" <?php echo $booking['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="confirmed" <?php echo $booking['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                                        <option value="completed" <?php echo $booking['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                        <option value="cancelled" <?php echo $booking['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                                    </select>
                                                    <input type="hidden" name="update_service_status" value="1">
                                                </form>
                                            </td>
                                            <td>
                                                <?php if ($booking['payment_status'] === 'pending'): ?>
                                                    <form method="POST" style="display:inline;" class="confirm-payment-form">
                                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                        <button type="submit" name="confirm_payment" class="btn btn-success btn-sm">Confirm Payment</button>
                                                    </form>
                                                <?php elseif ($booking['payment_status'] === 'completed'): ?>
                                                    <form method="POST" style="display:inline;" class="refund-payment-form">
                                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                        <button type="submit" name="refund_payment" class="btn btn-warning btn-sm">Refund Payment</button>
                                                    </form>
                                                <?php elseif ($booking['payment_status'] === 'failed'): ?>
                                                    <form method="POST" style="display:inline;" class="retry-payment-form">
                                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                        <button type="submit" name="retry_payment" class="btn btn-primary btn-sm">Retry Payment</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <nav>
                            <ul class="pagination">
                                <?php for ($i = 1; $i <= $totalBookingPages; $i++): ?>
                                    <li class="page-item <?php echo $i === $bookingsPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="admin_dashboard.php?<?php echo http_build_query([
                                            'bookings_page' => $i,
                                            'booking_search' => $bookingSearch,
                                            'service_filter' => $serviceFilter,
                                            'status_filter' => $statusFilter,
                                            'payment_status_filter' => $paymentStatusFilter,
                                            'booking_sort' => $bookingSort,
                                            'booking_order' => $bookingOrder,
                                            'clients_page' => $clientsPage,
                                            'client_search' => $clientSearch,
                                            'client_status_filter' => $clientStatusFilter,
                                            'client_sort' => $clientSort,
                                            'client_order' => $clientOrder,
                                            'logs_page' => $logsPage
                                        ]); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
            <h3 class="fw-bold mt-4">Service Cut-Off Controls</h3>
            <div class="row g-3">
                <div class="col-md-2">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="service" value="car_detailing">
                        <input type="hidden" name="value" value="<?php echo $carDetailingCutOff ? '1' : '0'; ?>">
                        <button type="submit" name="toggle_cutoff" class="btn btn-sm <?php echo $carDetailingCutOff ? 'btn-danger' : 'btn-success'; ?>">
                            Car Detailing: <?php echo $carDetailingCutOff ? 'Turn On' : 'Turn Off'; ?>
                        </button>
                    </form>
                </div>
                <div class="col-md-2">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="service" value="car_rental">
                        <input type="hidden" name="value" value="<?php echo $carRentalCutOff ? '1' : '0'; ?>">
                        <button type="submit" name="toggle_cutoff" class="btn btn-sm <?php echo $carRentalCutOff ? 'btn-danger' : 'btn-success'; ?>">
                            Car Rental: <?php echo $carRentalCutOff ? 'Turn On' : 'Turn Off'; ?>
                        </button>
                    </form>
                </div>
                <div class="col-md-2">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="service" value="valet">
                        <input type="hidden" name="value" value="<?php echo $valetCutOff ? '1' : '0'; ?>">
                        <button type="submit" name="toggle_cutoff" class="btn btn-sm <?php echo $valetCutOff ? 'btn-danger' : 'btn-success'; ?>">
                            Valet: <?php echo $valetCutOff ? 'Turn On' : 'Turn Off'; ?>
                        </button>
                    </form>
                </div>
                <div class="col-md-2">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="service" value="limousine">
                        <input type="hidden" name="value" value="<?php echo $limousineCutOff ? '1' : '0'; ?>">
                        <button type="submit" name="toggle_cutoff" class="btn btn-sm <?php echo $limousineCutOff ? 'btn-danger' : 'btn-success'; ?>">
                            Limousine: <?php echo $limousineCutOff ? 'Turn On' : 'Turn Off'; ?>
                        </button>
                    </form>
                </div>
                <div class="col-md-2">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="service" value="car_servicing">
                        <input type="hidden" name="value" value="<?php echo $carServicingCutOff ? '1' : '0'; ?>">
                        <button type="submit" name="toggle_cutoff" class="btn btn-sm <?php echo $carServicingCutOff ? 'btn-danger' : 'btn-success'; ?>">
                            Car Servicing: <?php echo $carServicingCutOff ? 'Turn On' : 'Turn Off'; ?>
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <!-- Clients Section -->
        <section id="clients" class="mb-5">
            <h2 class="fw-bold mb-4">Manage Clients</h2>
            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <input type="text" name="client_search" class="form-control" placeholder="Search by name, email, or phone" value="<?php echo htmlspecialchars($clientSearch); ?>">
                        </div>
                        <div class="col-md-2">
                            <select name="client_status_filter" class="form-control">
                                <option value="">All Statuses</option>
                                <option value="active" <?php echo $clientStatusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $clientStatusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="admin_dashboard.php" class="btn btn-secondary">Clear</a>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <?php if (empty($clients)): ?>
                        <p>No clients found.</p>
                    <?php else: ?>
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>
                                        <a href="admin_dashboard.php?<?php echo http_build_query(array_merge($_GET, ['client_sort' => 'name', 'client_order' => $clientSort === 'name' && $clientOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>">Name</a>
                                    </th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Service</th>
                                    <th>
                                        <a href="admin_dashboard.php?<?php echo http_build_query(array_merge($_GET, ['client_sort' => 'last_booking', 'client_order' => $clientSort === 'last_booking' && $clientOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>">Last Booking</a>
                                    </th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clients as $client): 
                                    $isActive = $client['last_booking'] && $client['last_booking'] > $oneMonthAgo;
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($client['id']); ?></td>
                                        <td><?php echo htmlspecialchars($client['name']); ?></td>
                                        <td><?php echo htmlspecialchars($client['email']); ?></td>
                                        <td><?php echo htmlspecialchars($client['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($client['service']); ?></td>
                                        <td><?php echo $client['last_booking'] ? htmlspecialchars($client['last_booking']) : 'N/A'; ?></td>
                                        <td><?php echo $isActive ? 'Active' : 'Inactive'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <nav>
                            <ul class="pagination">
                                <?php for ($i = 1; $i <= $totalClientPages; $i++): ?>
                                    <li class="page-item <?php echo $i === $clientsPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="admin_dashboard.php?<?php echo http_build_query([
                                            'bookings_page' => $bookingsPage,
                                            'booking_search' => $bookingSearch,
                                            'service_filter' => $serviceFilter,
                                            'status_filter' => $statusFilter,
                                            'payment_status_filter' => $paymentStatusFilter,
                                            'booking_sort' => $bookingSort,
                                            'booking_order' => $bookingOrder,
                                            'clients_page' => $i,
                                            'client_search' => $clientSearch,
                                            'client_status_filter' => $clientStatusFilter,
                                            'client_sort' => $clientSort,
                                            'client_order' => $clientOrder,
                                            'logs_page' => $logsPage
                                        ]); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Accounting Section -->
        <section id="accounting" class="mb-5">
            <h2 class="fw-bold mb-4">Accounting</h2>
            <div class="card">
                <div class="card-body">
                    <p><strong>Total Revenue:</strong> S$<?php echo number_format($totalRevenue, 2); ?></p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <a href="admin_dashboard.php?generate_report=monthly" class="btn btn-primary w-100 report-btn">Download Monthly Report</a>
                        </div>
                        <div class="col-md-4">
                            <a href="admin_dashboard.php?generate_report=half_yearly" class="btn btn-primary w-100 report-btn">Download Half-Yearly Report</a>
                        </div>
                        <div class="col-md-4">
                            <a href="admin_dashboard.php?generate_report=yearly" class="btn btn-primary w-100 report-btn">Download Yearly Report</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Audit Logs Section -->
        <section id="audit_logs" class="mb-5">
            <h2 class="fw-bold mb-4">Audit Logs</h2>
            <div class="card">
                <div class="card-body">
                    <?php if (empty($auditLogs)): ?>
                        <p>No audit logs found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Action</th>
                                        <th>Booking ID</th>
                                        <th>Admin Username</th>
                                        <th>Timestamp</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($auditLogs as $log): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($log['id']); ?></td>
                                            <td><?php echo htmlspecialchars($log['action']); ?></td>
                                            <td><?php echo htmlspecialchars($log['booking_id']); ?></td>
                                            <td><?php echo htmlspecialchars($log['admin_username'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <nav>
                            <ul class="pagination">
                                <?php for ($i = 1; $i <= $totalLogPages; $i++): ?>
                                    <li class="page-item <?php echo $i === $logsPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="admin_dashboard.php?<?php echo http_build_query([
                                            'bookings_page' => $bookingsPage,
                                            'booking_search' => $bookingSearch,
                                            'service_filter' => $serviceFilter,
                                            'status_filter' => $statusFilter,
                                            'payment_status_filter' => $paymentStatusFilter,
                                            'booking_sort' => $bookingSort,
                                            'booking_order' => $bookingOrder,
                                            'clients_page' => $clientsPage,
                                            'client_search' => $clientSearch,
                                            'client_status_filter' => $clientStatusFilter,
                                            'client_sort' => $clientSort,
                                            'client_order' => $clientOrder,
                                            'logs_page' => $i
                                        ]); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

    <div class="spinner-overlay" id="spinner">
        <div class="spinner"></div>
    </div>

    <footer class="bg-dark py-3">
        <div class="container text-center">
            <p>Backend by NXStudios, a part of NXGroup. | <a href="admin_dashboard.php">Back to Dashboard</a></p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show spinner on form submission and report generation
        document.querySelectorAll('form, .report-btn').forEach(element => {
            element.addEventListener('submit', () => {
                document.getElementById('spinner').style.display = 'flex';
            });
            if (element.classList.contains('report-btn')) {
                element.addEventListener('click', () => {
                    document.getElementById('spinner').style.display = 'flex';
                });
            }
        });

        // Confirmation prompt for manual payment confirmation
        document.querySelectorAll('.confirm-payment-form').forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!confirm('Are you sure you want to confirm this payment?')) {
                    e.preventDefault();
                    document.getElementById('spinner').style.display = 'none';
                }
            });
        });

        // Confirmation prompt for refund
        document.querySelectorAll('.refund-payment-form').forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!confirm('Are you sure you want to refund this payment? This action cannot be undone.')) {
                    e.preventDefault();
                    document.getElementById('spinner').style.display = 'none';
                }
            });
        });

        // Confirmation prompt for retry payment
        document.querySelectorAll('.retry-payment-form').forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!confirm('Are you sure you want to retry this payment? A new payment request will be generated.')) {
                    e.preventDefault();
                    document.getElementById('spinner').style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>