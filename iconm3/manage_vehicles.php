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

        if (isset($_POST['add_vehicle'])) {
            $model = trim(filter_input(INPUT_POST, 'model', FILTER_SANITIZE_STRING));
            $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING));
            $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
            $daily_price = filter_input(INPUT_POST, 'daily_price', FILTER_VALIDATE_FLOAT);
            $availability = filter_input(INPUT_POST, 'availability', FILTER_VALIDATE_INT) ? 1 : 0;
            $latitude = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
            $longitude = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);
            $address = trim(filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING));

            if (!$model || !$price || $price <= 0 || !$daily_price || $daily_price <= 0 || !$latitude || !$longitude || !$address) {
                throw new Exception('All required fields must be valid.');
            }

            $image = null;
            if (!empty($_FILES['image']['name'])) {
                $allowedTypes = ['image/jpeg', 'image/png'];
                if (!in_array($_FILES['image']['type'], $allowedTypes)) {
                    throw new Exception('Invalid image type. Only JPEG and PNG are allowed.');
                }
                $image = 'vehicle_' . time() . '_' . basename($_FILES['image']['name']);
                $imagePath = '../img/' . $image;
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $imagePath)) {
                    throw new Exception('Failed to upload image.');
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO vehicles (model, description, price, daily_price, availability, image, latitude, longitude, address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$model, $description, $price, $daily_price, $availability, $image, $latitude, $longitude, $address]);
            $_SESSION['success_message'] = 'Vehicle added successfully.';
        } elseif (isset($_POST['edit_vehicle'])) {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $model = trim(filter_input(INPUT_POST, 'model', FILTER_SANITIZE_STRING));
            $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING));
            $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
            $daily_price = filter_input(INPUT_POST, 'daily_price', FILTER_VALIDATE_FLOAT);
            $availability = filter_input(INPUT_POST, 'availability', FILTER_VALIDATE_INT) ? 1 : 0;
            $latitude = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
            $longitude = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);
            $address = trim(filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING));

            if (!$id || !$model || !$price || $price <= 0 || !$daily_price || $daily_price <= 0 || !$latitude || !$longitude || !$address) {
                throw new Exception('All required fields must be valid.');
            }

            $image = null;
            if (!empty($_FILES['image']['name'])) {
                $allowedTypes = ['image/jpeg', 'image/png'];
                if (!in_array($_FILES['image']['type'], $allowedTypes)) {
                    throw new Exception('Invalid image type. Only JPEG and PNG are allowed.');
                }
                $image = 'vehicle_' . time() . '_' . basename($_FILES['image']['name']);
                $imagePath = '../img/' . $image;
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $imagePath)) {
                    throw new Exception('Failed to upload image.');
                }
            }

            $stmt = $pdo->prepare("
                UPDATE vehicles 
                SET model = ?, description = ?, price = ?, daily_price = ?, availability = ?, 
                    image = COALESCE(?, image), latitude = ?, longitude = ?, address = ?
                WHERE id = ?
            ");
            $stmt->execute([$model, $description, $price, $daily_price, $availability, $image, $latitude, $longitude, $address, $id]);
            $_SESSION['success_message'] = 'Vehicle updated successfully.';
        } elseif (isset($_POST['delete_vehicle'])) {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) {
                throw new Exception('Invalid vehicle ID.');
            }
            $stmt = $pdo->prepare("DELETE FROM vehicles WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success_message'] = 'Vehicle deleted successfully.';
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        // error_log("Vehicle management error: " . $e->getMessage(), 3, './logs/php_errors.log');
    }
    header('Location: manage_vehicles.php');
    exit;
}

// Fetch vehicles
$vehiclesStmt = $pdo->query("SELECT * FROM vehicles ORDER BY created_at DESC");
$vehicles = $vehiclesStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Manage Vehicles - Icon Detailing Services</title>
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

        <section id="vehicles" class="mb-5" style="margin-top: 6rem;">
            <h2 class="fw-bold mb-4">Manage Vehicles</h2>

            <!-- Add Vehicle Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Add New Vehicle</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="addVehicleForm">
                        <input type="hidden" name="add_vehicle" value="1">
                        <div class="mb-3">
                            <label for="model" class="form-label">Model</label>
                            <input type="text" name="model" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea name="description" class="form-control"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="price" class="form-label">Price per Hour (SGD)</label>
                            <input type="number" name="price" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="daily_price" class="form-label">Price per Day (SGD)</label>
                            <input type="number" name="daily_price" class="form-control" step="0.01" min="0" required>
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
                            <label for="latitude" class="form-label">Latitude</label>
                            <input type="number" name="latitude" class="form-control" step="any" required>
                        </div>
                        <div class="mb-3">
                            <label for="longitude" class="form-label">Longitude</label>
                            <input type="number" name="longitude" class="form-control" step="any" required>
                        </div>
                        <div class="mb-3">
                            <label for="address" class="form-label">Address</label>
                            <textarea name="address" class="form-control" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Add Vehicle</button>
                    </form>
                </div>
            </div>

            <!-- Vehicles List -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Vehicles</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Model</th>
                                    <th>Hourly Price</th>
                                    <th>Daily Price</th>
                                    <th>Availability</th>
                                    <th>Image</th>
                                    <th>Latitude</th>
                                    <th>Longitude</th>
                                    <th>Address</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($vehicles)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center">No vehicles found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($vehicle['id']); ?></td>
                                            <td><?php echo htmlspecialchars($vehicle['model']); ?></td>
                                            <td>S$<?php echo number_format($vehicle['price'], 2); ?></td>
                                            <td>S$<?php echo number_format($vehicle['daily_price'], 2); ?></td>
                                            <td><?php echo $vehicle['availability'] ? 'Available' : 'Unavailable'; ?></td>
                                            <td>
                                                <?php if ($vehicle['image']): ?>
                                                    <img src="../img/<?php echo htmlspecialchars($vehicle['image']); ?>" alt="Vehicle Image" width="50">
                                                <?php else: ?>
                                                    No Image
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($vehicle['latitude']); ?></td>
                                            <td><?php echo htmlspecialchars($vehicle['longitude']); ?></td>
                                            <td><?php echo htmlspecialchars($vehicle['address']); ?></td>
                                            <td>
                                                <button class="btn btn-warning btn-sm me-2" data-bs-toggle="modal" data-bs-target="#editVehicleModal<?php echo $vehicle['id']; ?>">Edit</button>
                                                <form method="POST" class="d-inline delete-vehicle-form">
                                                    <input type="hidden" name="delete_vehicle" value="1">
                                                    <input type="hidden" name="id" value="<?php echo $vehicle['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                        <!-- Edit Vehicle Modal -->
                                        <div class="modal fade" id="editVehicleModal<?php echo $vehicle['id']; ?>" tabindex="-1" aria-labelledby="editVehicleModalLabel<?php echo $vehicle['id']; ?>" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title" id="editVehicleModalLabel<?php echo $vehicle['id']; ?>">Edit Vehicle</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <form method="POST" enctype="multipart/form-data">
                                                            <input type="hidden" name="edit_vehicle" value="1">
                                                            <input type="hidden" name="id" value="<?php echo $vehicle['id']; ?>">
                                                            <div class="mb-3">
                                                                <label for="model" class="form-label">Model</label>
                                                                <input type="text" name="model" class="form-control" value="<?php echo htmlspecialchars($vehicle['model']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="description" class="form-label">Description</label>
                                                                <textarea name="description" class="form-control"><?php echo htmlspecialchars($vehicle['description']); ?></textarea>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="price" class="form-label">Price per Hour (SGD)</label>
                                                                <input type="number" name="price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($vehicle['price']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="daily_price" class="form-label">Price per Day (SGD)</label>
                                                                <input type="number" name="daily_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($vehicle['daily_price']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="availability" class="form-label">Availability</label>
                                                                <select name="availability" class="form-control">
                                                                    <option value="1" <?php echo $vehicle['availability'] ? 'selected' : ''; ?>>Available</option>
                                                                    <option value="0" <?php echo !$vehicle['availability'] ? 'selected' : ''; ?>>Unavailable</option>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="image" class="form-label">Image</label>
                                                                <input type="file" name="image" class="form-control" accept="image/jpeg,image/png">
                                                                <?php if ($vehicle['image']): ?>
                                                                    <small>Current: <img src="../img/<?php echo htmlspecialchars($vehicle['image']); ?>" alt="Current Image" width="50"></small>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="latitude" class="form-label">Latitude</label>
                                                                <input type="number" name="latitude" class="form-control" step="any" value="<?php echo htmlspecialchars($vehicle['latitude']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="longitude" class="form-label">Longitude</label>
                                                                <input type="number" name="longitude" class="form-control" step="any" value="<?php echo htmlspecialchars($vehicle['longitude']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="address" class="form-label">Address</label>
                                                                <textarea name="address" class="form-control" required><?php echo htmlspecialchars($vehicle['address']); ?></textarea>
                                                            </div>
                                                            <button type="submit" class="btn btn-primary">Update Vehicle</button>
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

        // Confirmation prompt for vehicle deletion
        document.querySelectorAll('.delete-vehicle-form').forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!confirm('Are you sure you want to delete this vehicle?')) {
                    e.preventDefault();
                    document.getElementById('spinner').style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>