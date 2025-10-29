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

$successMessage = $_SESSION['success_message'] ?? '';
$errorMessage = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Pagination and Filtering for Slots
$slotsPerPage = 10;
$page = isset($_GET['page']) ? max(1, filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT)) : 1;
$offset = ($page - 1) * $slotsPerPage;

$serviceFilter = isset($_GET['service_filter']) ? filter_input(INPUT_GET, 'service_filter', FILTER_SANITIZE_STRING) : '';

try {
    // Count total slots for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM slots WHERE service_type = :service_type OR :service_type = ''");
    $countStmt->execute(['service_type' => $serviceFilter]);
    $totalSlots = $countStmt->fetchColumn();
    $totalPages = ceil($totalSlots / $slotsPerPage);

    // Fetch slots with pagination and filter
    $slotsStmt = $pdo->prepare("SELECT * FROM slots WHERE service_type = :service_type OR :service_type = '' ORDER BY service_type, slot_date, slot_time LIMIT :offset, :slots_per_page");
    $slotsStmt->bindValue(':service_type', $serviceFilter, PDO::PARAM_STR);
    $slotsStmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $slotsStmt->bindValue(':slots_per_page', (int)$slotsPerPage, PDO::PARAM_INT);
    $slotsStmt->execute();
    $allSlots = $slotsStmt->fetchAll();
} catch (PDOException $e) {
    $errorMessage = "Database error: " . $e->getMessage();
}

// Handle single slot creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_slot'])) {
    $service_type = filter_input(INPUT_POST, 'service_type', FILTER_SANITIZE_STRING);
    $slot_date = filter_input(INPUT_POST, 'slot_date', FILTER_SANITIZE_STRING);
    $slot_time = filter_input(INPUT_POST, 'slot_time', FILTER_SANITIZE_STRING);

    if (!$service_type || !$slot_date || !$slot_time) {
        $_SESSION['error_message'] = 'All fields are required for creating a slot.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM slots WHERE service_type = ? AND slot_date = ? AND slot_time = ?");
            $stmt->execute([$service_type, $slot_date, $slot_time]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['error_message'] = 'A slot with this date and time already exists for the selected service.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO slots (service_type, slot_date, slot_time, is_booked) VALUES (?, ?, ?, 0)");
                $stmt->execute([$service_type, $slot_date, $slot_time]);
                logAuditAction($pdo, "Admin created slot for $service_type on $slot_date $slot_time", 0, $_SESSION['admin_id'] ?? null);
                $_SESSION['success_message'] = 'Slot created successfully.';
            }
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        }
    }
    header('Location: manage_slots.php?page=' . $page . '&service_filter=' . urlencode($serviceFilter));
    exit;
}

// Handle bulk slot creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_bulk_slots'])) {
    $service_type = filter_input(INPUT_POST, 'service_type', FILTER_SANITIZE_STRING);
    $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
    $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);
    $start_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING);
    $end_time = filter_input(INPUT_POST, 'end_time', FILTER_SANITIZE_STRING);
    $interval = filter_input(INPUT_POST, 'interval', FILTER_SANITIZE_NUMBER_INT);

    $startDate = new DateTime($start_date);
    $endDate = new DateTime($end_date);

    if (!$service_type || !$start_date || !$end_date || !$start_time || !$end_time || $interval <= 0) {
        $_SESSION['error_message'] = 'All fields are required, and interval must be greater than 0.';
    } elseif ($startDate > $endDate) {
        $_SESSION['error_message'] = 'End date must be after start date.';
    } elseif (strtotime($start_time) > strtotime($end_time)) {
        $_SESSION['error_message'] = 'End time must be after start time.';
    } else {
        try {
            $intervalDays = $startDate->diff($endDate)->days;
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM slots WHERE service_type = ? AND slot_date = ? AND slot_time = ?");
            $insertStmt = $pdo->prepare("INSERT INTO slots (service_type, slot_date, slot_time, is_booked) VALUES (?, ?, ?, 0)");

            $slotsAdded = 0;
            for ($i = 0; $i <= $intervalDays; $i++) {
                $currentDate = clone $startDate;
                $currentDate->modify("+$i days");
                $dateStr = $currentDate->format('Y-m-d');

                $currentTime = new DateTime($start_time);
                $endTime = new DateTime($end_time);
                while ($currentTime <= $endTime) {
                    $timeStr = $currentTime->format('H:i:s');
                    $checkStmt->execute([$service_type, $dateStr, $timeStr]);
                    if ($checkStmt->fetchColumn() == 0) {
                        $insertStmt->execute([$service_type, $dateStr, $timeStr]);
                        $slotsAdded++;
                    }
                    $currentTime->modify("+$interval minutes");
                }
            }
            logAuditAction($pdo, "Admin created $slotsAdded slots for $service_type from $start_date to $end_date", 0, $_SESSION['admin_id'] ?? null);
            $_SESSION['success_message'] = "$slotsAdded slots created successfully.";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        }
    }
    header('Location: manage_slots.php?page=' . $page . '&service_filter=' . urlencode($serviceFilter));
    exit;
}

// Handle slot updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_slot'])) {
    $slot_id = filter_input(INPUT_POST, 'slot_id', FILTER_SANITIZE_NUMBER_INT);
    $slot_date = filter_input(INPUT_POST, 'slot_date', FILTER_SANITIZE_STRING);
    $slot_time = filter_input(INPUT_POST, 'slot_time', FILTER_SANITIZE_STRING);

    if (!$slot_date || !$slot_time) {
        $_SESSION['error_message'] = 'Date and time are required for updating a slot.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT service_type, is_booked FROM slots WHERE id = ?");
            $stmt->execute([$slot_id]);
            $slot = $stmt->fetch();
            if (!$slot) {
                $_SESSION['error_message'] = 'Slot not found.';
            } elseif ($slot['is_booked']) {
                $_SESSION['error_message'] = 'Cannot update a booked slot.';
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM slots WHERE service_type = ? AND slot_date = ? AND slot_time = ? AND id != ?");
                $stmt->execute([$slot['service_type'], $slot_date, $slot_time, $slot_id]);
                if ($stmt->fetchColumn() > 0) {
                    $_SESSION['error_message'] = 'A slot with this date and time already exists for the selected service.';
                } else {
                    $stmt = $pdo->prepare("UPDATE slots SET slot_date = ?, slot_time = ? WHERE id = ?");
                    $stmt->execute([$slot_date, $slot_time, $slot_id]);
                    logAuditAction($pdo, "Admin updated slot $slot_id to $slot_date $slot_time", 0, $_SESSION['admin_id'] ?? null);
                    $_SESSION['success_message'] = 'Slot updated successfully.';
                }
            }
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        }
    }
    header('Location: manage_slots.php?page=' . $page . '&service_filter=' . urlencode($serviceFilter));
    exit;
}

// Handle single slot deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_slot'])) {
    $slot_id = filter_input(INPUT_POST, 'slot_id', FILTER_SANITIZE_NUMBER_INT);
    try {
        $stmt = $pdo->prepare("SELECT is_booked FROM slots WHERE id = ?");
        $stmt->execute([$slot_id]);
        $slot = $stmt->fetch();
        if (!$slot) {
            $_SESSION['error_message'] = 'Slot not found.';
        } elseif ($slot['is_booked']) {
            $_SESSION['error_message'] = 'Cannot delete a booked slot.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM slots WHERE id = ?");
            $stmt->execute([$slot_id]);
            logAuditAction($pdo, "Admin deleted slot $slot_id", 0, $_SESSION['admin_id'] ?? null);
            $_SESSION['success_message'] = 'Slot deleted successfully.';
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    }
    header('Location: manage_slots.php?page=' . $page . '&service_filter=' . urlencode($serviceFilter));
    exit;
}

// Handle bulk slot deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_slots'])) {
    $service_type = filter_input(INPUT_POST, 'service_type', FILTER_SANITIZE_STRING);
    $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
    $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);

    if (!$service_type && !$start_date && !$end_date) {
        $_SESSION['error_message'] = 'At least one filter (service type, start date, or end date) is required for bulk deletion.';
    } else {
        try {
            $conditions = [];
            $params = [];

            if ($service_type) {
                $conditions[] = "service_type = :service_type";
                $params[':service_type'] = $service_type;
            }

            if ($start_date && $end_date) {
                $conditions[] = "slot_date BETWEEN :start_date AND :end_date";
                $params[':start_date'] = $start_date;
                $params[':end_date'] = $end_date;
            } elseif ($start_date) {
                $conditions[] = "slot_date >= :start_date";
                $params[':start_date'] = $start_date;
            } elseif ($end_date) {
                $conditions[] = "slot_date <= :end_date";
                $params[':end_date'] = $end_date;
            }

            $conditions[] = "is_booked = 0"; // Prevent deletion of booked slots

            $query = "DELETE FROM slots";
            if (!empty($conditions)) {
                $query .= " WHERE " . implode(" AND ", $conditions);
            }

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $deletedCount = $stmt->rowCount();
            logAuditAction($pdo, "Admin bulk deleted $deletedCount slots" . ($service_type ? " for $service_type" : ""), 0, $_SESSION['admin_id'] ?? null);
            $_SESSION['success_message'] = "$deletedCount slots deleted successfully.";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        }
    }
    header('Location: manage_slots.php?page=' . $page . '&service_filter=' . urlencode($serviceFilter));
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Manage Slots - Icon Detailing Services</title>
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
        .tooltip-icon {
            cursor: pointer;
            margin-left: 5px;
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

        <section id="slots" class="mb-5" style="margin-top: 6rem;">
            <h2 class="fw-bold mb-4">Manage Booking Slots (Car Detailing, Valet, Car Servicing)</h2>
            <div class="card">
                <div class="card-body">
                    <form method="POST" class="mb-4" id="createSlotForm">
                        <h4>Add Single Slot</h4>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="service_type" class="form-label">Service Type <i class="bi bi-info-circle tooltip-icon" data-bs-toggle="tooltip" title="Select the service for the slot. Valet slots apply to both standalone valet and car servicing with valet."></i></label>
                                <select class="form-control" id="service_type" name="service_type" required>
                                    <option value="car_detailing">Car Detailing</option>
                                    <option value="valet">Valet</option>
                                    <option value="car_servicing">Car Servicing</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="slot_date" class="form-label">Date</label>
                                <input type="date" class="form-control" id="slot_date" name="slot_date" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label for="slot_time" class="form-label">Time</label>
                                <input type="time" class="form-control" id="slot_time" name="slot_time" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label d-block">&nbsp;</label>
                                <button type="submit" name="create_slot" class="btn btn-success">Add Slot</button>
                            </div>
                        </div>
                    </form>

                    <form method="POST" class="mb-4" id="createBulkSlotsForm">
                        <h4>Bulk Add Slots</h4>
                        <div class="row g-3">
                            <div class="col-md-2">
                                <label for="bulk_service_type" class="form-label">Service Type <i class="bi bi-info-circle tooltip-icon" data-bs-toggle="tooltip" title="Select the service for bulk slots. Valet slots apply to both standalone valet and car servicing with valet."></i></label>
                                <select class="form-control" id="bulk_service_type" name="service_type" required>
                                    <option value="car_detailing">Car Detailing</option>
                                    <option value="valet">Valet</option>
                                    <option value="car_servicing">Car Servicing</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="start_date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label for="end_date" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label for="start_time" class="form-label">Start Time</label>
                                <input type="time" class="form-control" id="start_time" name="start_time" required>
                            </div>
                            <div class="col-md-2">
                                <label for="end_time" class="form-label">End Time</label>
                                <input type="time" class="form-control" id="end_time" name="end_time" required>
                            </div>
                            <div class="col-md-1">
                                <label for="interval" class="form-label">Interval (min)</label>
                                <input type="number" class="form-control" id="interval" name="interval" min="1" value="60" required>
                            </div>
                            <div class="col-md-1">
                                <label class="form-label d-block">&nbsp;</label>
                                <button type="submit" name="create_bulk_slots" class="btn btn-success">Generate</button>
                            </div>
                        </div>
                    </form>

                    <form method="POST" class="mb-4" id="bulkDeleteForm">
                        <h4>Bulk Delete Slots</h4>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="delete_service_type" class="form-label">Service Type <i class="bi bi-info-circle tooltip-icon" data-bs-toggle="tooltip" title="Filter slots to delete. Leave blank to select all services."></i></label>
                                <select class="form-control" id="delete_service_type" name="service_type">
                                    <option value="">All Services</option>
                                    <option value="car_detailing" <?php echo $serviceFilter === 'car_detailing' ? 'selected' : ''; ?>>Car Detailing</option>
                                    <option value="valet" <?php echo $serviceFilter === 'valet' ? 'selected' : ''; ?>>Valet</option>
                                    <option value="car_servicing" <?php echo $serviceFilter === 'car_servicing' ? 'selected' : ''; ?>>Car Servicing</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="delete_start_date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="delete_start_date" name="start_date">
                            </div>
                            <div class="col-md-3">
                                <label for="delete_end_date" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="delete_end_date" name="end_date">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label d-block">&nbsp;</label>
                                <button type="submit" name="bulk_delete_slots" class="btn btn-danger">Delete Slots</button>
                            </div>
                        </div>
                    </form>

                    <form method="GET" class="mb-4">
                        <input type="hidden" name="page" value="1">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="service_filter" class="form-label">Filter by Service Type</label>
                                <select class="form-control" id="service_filter" name="service_filter" onchange="this.form.submit()">
                                    <option value="">All Services</option>
                                    <option value="car_detailing" <?php echo $serviceFilter === 'car_detailing' ? 'selected' : ''; ?>>Car Detailing</option>
                                    <option value="valet" <?php echo $serviceFilter === 'valet' ? 'selected' : ''; ?>>Valet</option>
                                    <option value="car_servicing" <?php echo $serviceFilter === 'car_servicing' ? 'selected' : ''; ?>>Car Servicing</option>
                                </select>
                            </div>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Service Type</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Booked</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allSlots)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No slots found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($allSlots as $slot): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($slot['id']); ?></td>
                                            <td><?php echo htmlspecialchars($slot['service_type']); ?></td>
                                            <td>
                                                <form method="POST" class="update-slot-form">
                                                    <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                                                    <input type="date" name="slot_date" value="<?php echo htmlspecialchars($slot['slot_date']); ?>" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                                            </td>
                                            <td>
                                                <input type="time" name="slot_time" value="<?php echo htmlspecialchars($slot['slot_time']); ?>" class="form-control" required>
                                            </td>
                                            <td><?php echo $slot['is_booked'] ? 'Yes' : 'No'; ?></td>
                                            <td>
                                                <button type="submit" name="update_slot" class="btn btn-primary btn-sm me-2" <?php echo $slot['is_booked'] ? 'disabled' : ''; ?>>Update</button>
                                                <button type="submit" name="delete_slot" class="btn btn-danger btn-sm delete-slot-btn" data-booked="<?php echo $slot['is_booked'] ? 'true' : 'false'; ?>" <?php echo $slot['is_booked'] ? 'disabled' : ''; ?>>Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <nav aria-label="Slots pagination">
                        <ul class="pagination">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&service_filter=<?php echo urlencode($serviceFilter); ?>">Previous</a>
                            </li>
                            <li class="page-item <?php echo $page === 1 ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=1&service_filter=<?php echo urlencode($serviceFilter); ?>">1</a>
                            </li>
                            <?php if ($page > 4): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                            <?php endif; ?>
                            <?php
                            $startPage = max(2, $page - 2);
                            $endPage = min($totalPages - 1, $page + 2);
                            for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&service_filter=<?php echo urlencode($serviceFilter); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                            <?php endif; ?>
                            <?php if ($totalPages > 1): ?>
                                <li class="page-item <?php echo $page === $totalPages ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $totalPages; ?>&service_filter=<?php echo urlencode($serviceFilter); ?>"><?php echo $totalPages; ?></a>
                                </li>
                            <?php endif; ?>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&service_filter=<?php echo urlencode($serviceFilter); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
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
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Show spinner on form submission
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', () => {
                document.getElementById('spinner').style.display = 'flex';
            });
        });

        // Confirmation prompt for deleting single slots
        document.querySelectorAll('.delete-slot-btn').forEach(button => {
            button.addEventListener('click', function(event) {
                const isBooked = this.getAttribute('data-booked') === 'true';
                if (isBooked) {
                    alert('This slot is booked and cannot be deleted.');
                    event.preventDefault();
                    document.getElementById('spinner').style.display = 'none';
                } else if (!confirm('Are you sure you want to delete this slot?')) {
                    event.preventDefault();
                    document.getElementById('spinner').style.display = 'none';
                }
            });
        });

        // Confirmation prompt for bulk delete
        document.getElementById('bulkDeleteForm').addEventListener('submit', function(event) {
            if (!confirm('Are you sure you want to delete all unbooked slots matching the selected filters? This action cannot be undone.')) {
                event.preventDefault();
                document.getElementById('spinner').style.display = 'none';
            }
        });
    </script>
</body>
</html>