<?php
session_start();
require 'includes/db.php'; // Make sure this path is correct

// Redirect if already logged in (as admin or other role)
if (isset($_SESSION['user_id'])) {
    // Redirect based on the role stored in the session
    if ($_SESSION['role'] == 'admin') {
        header("Location: admin_dashboard.php");
    } elseif ($_SESSION['role'] == 'faculty') {
        header("Location: faculty_dashboard.php");
    } else { // Assume student
        header("Location: student_dashboard.php");
    }
    exit;
}

$error_message = '';
$email_value = ''; // To repopulate email field

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // *** CHANGE: Retrieve email instead of college_uid ***
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $email_value = htmlspecialchars($email); // For repopulation

    // *** CHANGE: Update validation check ***
    if (empty($email) || empty($password)) {
        $error_message = "Admin Email and Password are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
         $error_message = "Invalid email format provided.";
    } else {
        // --- Login using Email, checking specifically the 'admins' table ---
        // *** CHANGE: Query admins table by email ***
        $stmt = $conn->prepare(
            "SELECT id, password FROM admins WHERE email = ?" // Query the 'admins' table by email
        );
        if (!$stmt) {
             error_log("Admin Login Prepare failed (Email): " . $conn->error);
             $error_message = "An internal error occurred. Please try again later.";
        } else {
            // *** CHANGE: Bind email parameter ***
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $admin_user = $result->fetch_assoc(); // Use a distinct variable name
                // Verify password
                if (password_verify($password, $admin_user['password'])) {
                    // Admin Login successful
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $admin_user['id']; // Use the ID from the admins table
                    $_SESSION['role'] = 'admin'; // Explicitly set role in session
                    header("Location: admin_dashboard.php"); // Redirect to Admin Dashboard
                    exit;
                } else {
                    // Password incorrect
                    $error_message = "Invalid Admin Email or Password.";
                }
            } else {
                // Email not found in admins table
                $error_message = "Invalid Admin Email or Password.";
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Reusing styles from regular login */
         :root { /* ... CSS variables ... */ }
        body { background: linear-gradient(135deg, #3a0ca3 0%, #7209b7 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; font-family: 'Poppins', sans-serif; padding: 20px; }
        .login-container { background-color: #ffffff; padding: 40px 45px; border-radius: 12px; box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2); width: 100%; max-width: 450px; }
        .form-header { font-weight: 700; font-size: 1.8rem; color: var(--primary-color); text-align: center; margin-bottom: 30px; }
        .form-label { font-weight: 600; color: #444; }
        .form-control { border-radius: 8px; padding: 12px 15px; font-size: 1rem; }
        .btn-login { background: linear-gradient(90deg, #3a0ca3, #560bad); border: none; padding: 14px 20px; font-size: 1.1rem; font-weight: 600; color: #fff; border-radius: 8px; width: 100%; margin-top: 20px; }
        .btn-login:hover { background: linear-gradient(90deg, #560bad, #3a0ca3); }
        .alert { border-radius: 8px; }
         /* Added style for the link back */
         .other-login-link { text-align:center; margin-top: 15px; font-size: 0.9em; }
         .other-login-link a { color: #555; text-decoration: none; }
         .other-login-link a:hover { text-decoration: underline; color: #000; }
    </style>
</head>
<body>
<div class="login-container">
    <div class="form-header">Administrator Login</div>
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger text-center"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>
    <form method="post" action="admin_login.php">
        <div class="mb-3">
             <!-- *** CHANGE: Label and Input Name/ID/Placeholder *** -->
            <label for="email" class="form-label">Admin Email</label>
            <input type="email" id="email" name="email" class="form-control" placeholder="Enter your admin email" required value="<?= $email_value ?>">
        </div>
        <div class="mb-4">
            <label for="password" class="form-label">Password</label>
            <input type="password" id="password" name="password" class="form-control" placeholder="Enter password" required>
        </div>
        <button type="submit" class="btn-login">Login</button>
    </form>
    <div class="other-login-link">
        <a href="login.php">Faculty/Student Login</a>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>