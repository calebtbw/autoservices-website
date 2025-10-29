<?php
session_start();
require 'db.php';

// Prevent crawlers
header('X-Robots-Tag: noindex, nofollow');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

$successMessage = $_SESSION['success_message'] ?? '';
$errorMessage = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        if (isset($_POST['add_limousine'])) {
            $model = trim(filter_input(INPUT_POST, 'model', FILTER_SANITIZE_STRING));
            $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING));
            $availability = filter_input(INPUT_POST, 'availability', FILTER_VALIDATE_INT) ? 1 : 0;
            $hourly_price = filter_input(INPUT_POST, 'hourly_price', FILTER_VALIDATE_FLOAT);
            $point_to_point_price = filter_input(INPUT_POST, 'point_to_point_price', FILTER_VALIDATE_FLOAT);
            $airport_departure_price = filter_input(INPUT_POST, 'airport_departure_price', FILTER_VALIDATE_FLOAT);
            $airport_arrival_price = filter_input(INPUT_POST, 'airport_arrival_price', FILTER_VALIDATE_FLOAT);
            $corporate_price = filter_input(INPUT_POST, 'corporate_price', FILTER_VALIDATE_FLOAT);
            $events_price = filter_input(INPUT_POST, 'events_price', FILTER_VALIDATE_FLOAT);

            if (!$model || !$hourly_price || !$point_to_point_price || !$airport_departure_price || !$airport_arrival_price || !$corporate_price || !$events_price ||
                $hourly_price <= 0 || $point_to_point_price <= 0 || $airport_departure_price <= 0 || $airport_arrival_price <= 0 || $corporate_price <= 0 || $events_price <= 0) {
                throw new Exception('Model and all service type prices are required and must be valid.');
            }

            $service_types = [
                'Hourly' => $hourly_price,
                'Point-to-Point Transfer' => $point_to_point_price,
                'Airport Departure' => $airport_departure_price,
                'Airport Arrival' => $airport_arrival_price,
                'Corporate' => $corporate_price,
                'Events' => $events_price
            ];

            $image = null;
            if (!empty($_FILES['image']['name'])) {
                $allowedTypes = ['image/jpeg', 'image/png'];
                if (!in_array($_FILES['image']['type'], $allowedTypes)) {
                    throw new Exception('Invalid image type. Only JPEG and PNG are allowed.');
                }
                $image = 'limo_' . time() . '_' . basename($_FILES['image']['name']);
                $imagePath = '../img/' . $image;
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $imagePath)) {
                    throw new Exception('Failed to upload image.');
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO limousines (model, description, image, availability, service_types)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$model, $description, $image, $availability, json_encode($service_types)]);
            $_SESSION['success_message'] = 'Limousine added successfully.';
        } elseif (isset($_POST['edit_limousine'])) {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $model = trim(filter_input(INPUT_POST, 'model', FILTER_SANITIZE_STRING));
            $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING));
            $availability = filter_input(INPUT_POST, 'availability', FILTER_VALIDATE_INT) ? 1 : 0;
            $hourly_price = filter_input(INPUT_POST, 'hourly_price', FILTER_VALIDATE_FLOAT);
            $point_to_point_price = filter_input(INPUT_POST, 'point_to_point_price', FILTER_VALIDATE_FLOAT);
            $airport_departure_price = filter_input(INPUT_POST, 'airport_departure_price', FILTER_VALIDATE_FLOAT);
            $airport_arrival_price = filter_input(INPUT_POST, 'airport_arrival_price', FILTER_VALIDATE_FLOAT);
            $corporate_price = filter_input(INPUT_POST, 'corporate_price', FILTER_VALIDATE_FLOAT);
            $events_price = filter_input(INPUT_POST, 'events_price', FILTER_VALIDATE_FLOAT);

            if (!$id || !$model || !$hourly_price || !$point_to_point_price || !$airport_departure_price || !$airport_arrival_price || !$corporate_price || !$events_price ||
                $hourly_price <= 0 || $point_to_point_price <= 0 || $airport_departure_price <= 0 || $airport_arrival_price <= 0 || $corporate_price <= 0 || $events_price <= 0) {
                throw new Exception('Model and all service type prices are required and must be valid.');
            }

            $service_types = [
                'Hourly' => $hourly_price,
                'Point-to-Point Transfer' => $point_to_point_price,
                'Airport Departure' => $airport_departure_price,
                'Airport Arrival' => $airport_arrival_price,
                'Corporate' => $corporate_price,
                'Events' => $events_price
            ];

            $image = null;
            if (!empty($_FILES['image']['name'])) {
                $allowedTypes = ['image/jpeg', 'image/png'];
                if (!in_array($_FILES['image']['type'], $allowedTypes)) {
                    throw new Exception('Invalid image type. Only JPEG and PNG are allowed.');
                }
                $image = 'limo_' . time() . '_' . basename($_FILES['image']['name']);
                $imagePath = '../img/' . $image;
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $imagePath)) {
                    throw new Exception('Failed to upload image.');
                }
            }

            $stmt = $pdo->prepare("
                UPDATE limousines 
                SET model = ?, description = ?, image = COALESCE(?, image), availability = ?, service_types = ?
                WHERE id = ?
            ");
            $stmt->execute([$model, $description, $image, $availability, json_encode($service_types), $id]);
            $_SESSION['success_message'] = 'Limousine updated successfully.';
        } elseif (isset($_POST['delete_limousine'])) {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) {
                throw new Exception('Invalid limousine ID.');
            }
            $stmt = $pdo->prepare("DELETE FROM limousines WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success_message'] = 'Limousine deleted successfully.';
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        // error_log("Limousine management error: " . $e->getMessage(), 3, './logs/php_errors.log');
    }
    header('Location: manage_limousines.php');
    exit;
}

// Fetch limousines
$limousinesStmt = $pdo->query("SELECT * FROM limousines ORDER BY created_at DESC");
$limousines = $limousinesStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Manage Limousines - Icon Detailing Services</title>
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

        <section id="limousines" class="mb-5" style="margin-top: 6rem;">
            <h2 class="fw-bold mb-4">Manage Limousines</h2>

            <!-- Add Limousine Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Add New Limousine</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive"></div>
                    <form method="POST" enctype="multipart/form-data" id="addLimousineForm">
                        <input type="hidden" name="add_limousine" value="1">
                        <div class="mb-3">
                            <label for="model" class="form-label">Model</label>
                            <input type="text" name="model" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea name="description" class="form-control"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="availability" class="form-label">Availability</label>
                            <select name="availability" class="form-control">
                                <option value="1">Available</option>
                                <option value="0">Unavailable</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="image" class="form-label">Image</label>
                            <input type="file" name="image" class="form-control" accept="image/jpeg,image/png">
                        </div>
                        <div class="mb-3">
                            <label for="hourly_price" class="form-label">Hourly Price (SGD)</label>
                            <input type="number" name="hourly_price" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="point_to_point_price" class="form-label">Point-to-Point Transfer Price (SGD)</label>
                            <input type="number" name="point_to_point_price" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="airport_departure_price" class="form-label">Airport Departure Price (SGD)</label>
                            <input type="number" name="airport_departure_price" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="airport_arrival_price" class="form-label">Airport Arrival Price (SGD)</label>
                            <input type="number" name="airport_arrival_price" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="corporate_price" class="form-label">Corporate Price (SGD)</label>
                            <input type="number" name="corporate_price" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="events_price" class="form-label">Events Price (SGD)</label>
                            <input type="number" name="events_price" class="form-control" step="0.01" min="0" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Add Limousine</button>
                    </form>
                </div>
            </div>

            <!-- Limousines List -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Limousines</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Model</th>
                                    <th>Availability</th>
                                    <th>Image</th>
                                    <th>Service Type Prices (SGD)</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($limousines)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No limousines found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($limousines as $limousine): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($limousine['id']); ?></td>
                                            <td><?php echo htmlspecialchars($limousine['model']); ?></td>
                                            <td><?php echo $limousine['availability'] ? 'Available' : 'Unavailable'; ?></td>
                                            <td>
                                                <?php if ($limousine['image']): ?>
                                                    <img src="../img/<?php echo htmlspecialchars($limousine['image']); ?>" alt="Limousine Image" width="50">
                                                <?php else: ?>
                                                    No Image
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $service_types = json_decode($limousine['service_types'], true);
                                                echo 'Hourly: S$' . number_format($service_types['Hourly'], 2) . '<br>';
                                                echo 'Point-to-Point: S$' . number_format($service_types['Point-to-Point Transfer'], 2) . '<br>';
                                                echo 'Airport Departure: S$' . number_format($service_types['Airport Departure'], 2) . '<br>';
                                                echo 'Airport Arrival: S$' . number_format($service_types['Airport Arrival'], 2) . '<br>';
                                                echo 'Corporate: S$' . number_format($service_types['Corporate'], 2) . '<br>';
                                                echo 'Events: S$' . number_format($service_types['Events'], 2);
                                                ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-warning btn-sm me-2" data-bs-toggle="modal" data-bs-target="#editLimousineModal<?php echo $limousine['id']; ?>">Edit</button>
                                                <form method="POST" class="d-inline delete-limousine-form">
                                                    <input type="hidden" name="delete_limousine" value="1">
                                                    <input type="hidden" name="id" value="<?php echo $limousine['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                        <!-- Edit Limousine Modal -->
                                        <div class="modal fade" id="editLimousineModal<?php echo $limousine['id']; ?>" tabindex="-1" aria-labelledby="editLimousineModalLabel<?php echo $limousine['id']; ?>" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title" id="editLimousineModalLabel<?php echo $limousine['id']; ?>">Edit Limousine</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <form method="POST" enctype="multipart/form-data">
                                                            <input type="hidden" name="edit_limousine" value="1">
                                                            <input type="hidden" name="id" value="<?php echo $limousine['id']; ?>">
                                                            <div class="mb-3">
                                                                <label for="model" class="form-label">Model</label>
                                                                <input type="text" name="model" class="form-control" value="<?php echo htmlspecialchars($limousine['model']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="description" class="form-label">Description</label>
                                                                <textarea name="description" class="form-control"><?php echo htmlspecialchars($limousine['description']); ?></textarea>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="availability" class="form-label">Availability</label>
                                                                <select name="availability" class="form-control">
                                                                    <option value="1" <?php echo $limousine['availability'] ? 'selected' : ''; ?>>Available</option>
                                                                    <option value="0" <?php echo !$limousine['availability'] ? 'selected' : ''; ?>>Unavailable</option>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="image" class="form-label">Image</label>
                                                                <input type="file" name="image" class="form-control" accept="image/jpeg,image/png">
                                                                <?php if ($limousine['image']): ?>
                                                                    <small>Current: <img src="../img/<?php echo htmlspecialchars($limousine['image']); ?>" alt="Current Image" width="50"></small>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="hourly_price" class="form-label">Hourly Price (SGD)</label>
                                                                <input type="number" name="hourly_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($service_types['Hourly']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="point_to_point_price" class="form-label">Point-to-Point Transfer Price (SGD)</label>
                                                                <input type="number" name="point_to_point_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($service_types['Point-to-Point Transfer']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="airport_departure_price" class="form-label">Airport Departure Price (SGD)</label>
                                                                <input type="number" name="airport_departure_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($service_types['Airport Departure']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="airport_arrival_price" class="form-label">Airport Arrival Price (SGD)</label>
                                                                <input type="number" name="airport_arrival_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($service_types['Airport Arrival']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="corporate_price" class="form-label">Corporate Price (SGD)</label>
                                                                <input type="number" name="corporate_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($service_types['Corporate']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="events_price" class="form-label">Events Price (SGD)</label>
                                                                <input type="number" name="events_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($service_types['Events']); ?>" required>
                                                            </div>
                                                            <button type="submit" class="btn btn-primary">Update Limousine</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
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
        // Show spinner on form submission
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', () => {
                document.getElementById('spinner').style.display = 'flex';
            });
        });

        // Confirmation prompt for limousine deletion
        document.querySelectorAll('.delete-limousine-form').forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!confirm('Are you sure you want to delete this limousine?')) {
                    e.preventDefault();
                    document.getElementById('spinner').style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>