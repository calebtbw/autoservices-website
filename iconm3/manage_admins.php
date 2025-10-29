<?php
require 'db.php';
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

// Fetch admins
try {
    $adminsStmt = $pdo->query("SELECT * FROM admins");
    $admins = $adminsStmt->fetchAll();
} catch (PDOException $e) {
    $errorMessage = "Database error: " . $e->getMessage();
    // error_log("Manage admins error: " . $e->getMessage(), 3, './logs/php_errors.log');
}

// Handle admin creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin'])) {
    $username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING));
    $password = trim(filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING));

    if (empty($username) || empty($password)) {
        $_SESSION['error_message'] = 'Username and password are required.';
    } elseif (strlen($password) < 12) {
        $_SESSION['error_message'] = 'Password must be at least 12 characters long.';
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{12,}$/', $password)) {
        $_SESSION['error_message'] = 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['error_message'] = 'Username already exists.';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
                $stmt->execute([$username, $hashedPassword]);
                $_SESSION['success_message'] = 'Admin added successfully.';
            }
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Database error: " . $e->getMessage();
            // error_log("Add admin error: " . $e->getMessage(), 3, './logs/php_errors.log');
        }
    }
    header('Location: manage_admins.php');
    exit;
}

// Handle admin deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_admin'])) {
    $admin_id = filter_input(INPUT_POST, 'admin_id', FILTER_SANITIZE_NUMBER_INT);
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins");
        $stmt->execute();
        if ($stmt->fetchColumn() <= 1) {
            $_SESSION['error_message'] = 'Cannot delete the last admin.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM admins WHERE id = ?");
            $stmt->execute([$admin_id]);
            $_SESSION['success_message'] = 'Admin deleted successfully.';
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        // error_log("Delete admin error: " . $e->getMessage(), 3, './logs/php_errors.log');
    }
    header('Location: manage_admins.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Manage Admins - Icon Detailing Services</title>
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

        <section id="admins" class="mb-5" style="margin-top: 6rem;">
            <h2 class="fw-bold mb-4">Manage Admins</h2>
            <div class="card">
                <div class="card-body">
                    <form method="POST" class="mb-4" id="addAdminForm">
                        <h4>Add New Admin</h4>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="new_username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="new_username" name="username" required>
                            </div>
                            <div class="col-md-4">
                                <label for="new_password" class="form-label">Password (min 12 characters)</label>
                                <input type="password" class="form-control" id="new_password" name="password" required minlength="12">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label d-block">&nbsp;</label>
                                <button type="submit" name="add_admin" class="btn btn-success">Add Admin</button>
                            </div>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($admins)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center">No admins found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($admins as $admin): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($admin['id']); ?></td>
                                            <td><?php echo htmlspecialchars($admin['username']); ?></td>
                                            <td>
                                                <form method="POST" class="d-inline delete-admin-form">
                                                    <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                                                    <button type="submit" name="delete_admin" class="btn btn-danger btn-sm">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
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

        // Confirmation prompt for admin deletion
        document.querySelectorAll('.delete-admin-form').forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!confirm('Are you sure you want to delete this admin?')) {
                    e.preventDefault();
                    document.getElementById('spinner').style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>