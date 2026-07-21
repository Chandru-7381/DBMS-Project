<?php
session_start();
require 'includes/db.php';

// --- Authentication: Ensure user is logged in and *needs* to change password ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['temp_must_change_password']) || !$_SESSION['temp_must_change_password']) {
    // If not logged in or doesn't need change, redirect away (e.g., to login or appropriate dashboard)
     if(isset($_SESSION['user_id'])){
          header("Location: " . ($_SESSION['role'] == 'faculty' || $_SESSION['role'] == 'admin' ? 'faculty_dashboard.php' : 'student_dashboard.php'));
     } else {
          header("Location: login.php");
     }
    exit;
}

$user_id = $_SESSION['user_id'];
$error_message = '';
$success_message = '';

// Check for info message from login page
if (isset($_SESSION['info_message'])) {
     $info_message = $_SESSION['info_message'];
     unset($_SESSION['info_message']);
}


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($new_password) || empty($confirm_password)) {
        $error_message = "Both password fields are required.";
    } elseif (strlen($new_password) < 6) { // Enforce minimum length (or your policy)
        $error_message = "Password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } else {
        // Optional: Check if the new password is the same as the default one (prevent reusing default)
        // $default_password_plain = 'acharya@1234'; // Make sure this is defined same as elsewhere
        // if ($new_password === $default_password_plain) {
        //     $error_message = "New password cannot be the same as the default password.";
        // } else {

            // Hash the new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            if (!$hashed_password) {
                $error_message = "Error processing password. Please try again.";
                error_log("Password hashing failed for user ID: " . $user_id);
            } else {
                // Update password and reset the flag in the database
                $conn->begin_transaction();
                try {
                    // Update password
                    $stmt_update_pass = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    if(!$stmt_update_pass) throw new Exception("DB Error 1");
                    $stmt_update_pass->bind_param("si", $hashed_password, $user_id);
                    if(!$stmt_update_pass->execute()) throw new Exception("DB Error 2: ".$stmt_update_pass->error);
                    $stmt_update_pass->close();

                    // Update flag
                    $stmt_update_flag = $conn->prepare("UPDATE users SET must_change_password = FALSE WHERE id = ?");
                    if(!$stmt_update_flag) throw new Exception("DB Error 3");
                    $stmt_update_flag->bind_param("i", $user_id);
                    if(!$stmt_update_flag->execute()) throw new Exception("DB Error 4: ".$stmt_update_flag->error);
                    $stmt_update_flag->close();

                    $conn->commit();

                    // Password changed successfully!
                    $success_message = "Password updated successfully! Redirecting to your dashboard...";

                    // Clear the temporary session flag
                    unset($_SESSION['temp_must_change_password']);

                    // Redirect to the appropriate dashboard after a short delay
                    $redirect_url = ($_SESSION['role'] == 'faculty' || $_SESSION['role'] == 'admin') ? 'faculty_dashboard.php' : 'student_dashboard.php';
                    header("Refresh: 3; url=" . $redirect_url);
                    // exit(); // Exit after header if needed, but success message will show briefly

                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Failed to update password due to a database error.";
                    error_log("Password Change Error for user ID $user_id: " . $e->getMessage());
                }
            }
        // } // Optional: End else for checking against default password
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Change Default Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
         /* Basic Styling - Reuse login styles */
         :root { /* ... CSS variables ... */ }
         body { background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; font-family: 'Poppins', sans-serif; padding: 20px; }
         .change-password-container { background-color: #ffffff; padding: 40px 45px; border-radius: 12px; box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2); width: 100%; max-width: 480px; }
         .form-header { font-weight: 700; font-size: 1.6rem; color: #333; text-align: center; margin-bottom: 25px; }
         .form-label { font-weight: 600; color: #444; }
         .form-control { border-radius: 8px; padding: 12px 15px; font-size: 1rem; margin-bottom: 1rem; }
         .btn-submit { background: linear-gradient(90deg, #6a11cb, #2575fc); border: none; padding: 14px 20px; font-size: 1.1rem; font-weight: 600; color: #fff; border-radius: 8px; width: 100%; margin-top: 15px; }
         .btn-submit:hover { background: linear-gradient(90deg, #2575fc, #6a11cb); }
         .alert { border-radius: 8px; }
    </style>
</head>
<body>
    <div class="change-password-container">
        <div class="form-header">Update Your Password</div>

        <?php if (!empty($info_message)): ?>
            <div class="alert alert-info"><?= htmlspecialchars($info_message) ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>

        <?php if (empty($success_message)): // Hide form after success ?>
            <form method="post" action="change_password.php">
                <div class="mb-3">
                    <label for="new_password" class="form-label">New Password (min. 6 characters)</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                </div>
                <button type="submit" class="btn-submit">Update Password</button>
            </form>
        <?php endif; ?>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>