<?php
session_start();
require 'includes/db.php'; // Make sure this path is correct

// Redirect if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    // Determine redirect target based on role
    $redirect_url = 'student_dashboard.php'; // Default for student
    if ($_SESSION['role'] == 'faculty') {
        $redirect_url = 'faculty_dashboard.php';
    } elseif ($_SESSION['role'] == 'admin') {
        $redirect_url = 'admin_dashboard.php'; // Redirect admin to admin dashboard
    }
    header("Location: " . $redirect_url);
    exit;
}

$error_message = '';
$success_message = ''; // For messages from other pages like reset success
$uid_value = ''; // To repopulate UID field

// Check for messages from other pages
if (isset($_SESSION['signup_success'])) {
    $success_message = $_SESSION['signup_success'];
    unset($_SESSION['signup_success']);
}
if (isset($_SESSION['reset_success'])) {
    $success_message = $_SESSION['reset_success'];
    unset($_SESSION['reset_success']);
}
if (isset($_SESSION['reset_error'])) {
    $error_message = $_SESSION['reset_error'];
    unset($_SESSION['reset_error']);
}


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $college_uid = trim($_POST['college_uid'] ?? '');
    $password = $_POST['password'] ?? '';
    $uid_value = htmlspecialchars($college_uid);

    if (empty($college_uid) || empty($password)) {
        $error_message = "College UID and Password are required.";
    } else {
        // --- Login using College UID - Fetch necessary fields ---
        $stmt = $conn->prepare(
            "SELECT id, password, role, must_change_password FROM users WHERE college_uid = ?"
        );
        if (!$stmt) {
             error_log("Login Prepare failed (UID): " . $conn->error);
             $error_message = "An internal error occurred. Please try again later.";
        } else {
            $stmt->bind_param("s", $college_uid);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                // Verify password
                if (password_verify($password, $user['password'])) {
                    // --- Password Correct - Set session and check flags ---
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['temp_must_change_password'] = (bool)$user['must_change_password']; // Store flag temporarily

                    // Redirect based on password change flag and role
                    if ($_SESSION['temp_must_change_password']) {
                        $_SESSION['info_message'] = "Welcome! Please update your default password.";
                        header("Location: change_password.php");
                        exit;
                    } else {
                         // Determine correct dashboard
                         $redirect_url = 'student_dashboard.php'; // Default
                         if ($user['role'] == 'faculty') {
                             $redirect_url = 'faculty_dashboard.php';
                         } elseif ($user['role'] == 'admin') {
                             $redirect_url = 'admin_dashboard.php'; // Direct admin here
                         }
                         header("Location: " . $redirect_url);
                         exit;
                    }
                    // --- End Redirection Logic ---

                } else { $error_message = "Invalid College UID or Password."; }
            } else { $error_message = "Invalid College UID or Password."; }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | University Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Reusing styles from previous login/signup styling */
         :root {
            --primary-color: #4a00e0; --secondary-color: #8e2de2; --accent-color: #00bcd4;
            --text-color: #444; --light-gray: #f8f9fa; --border-color: #dee2e6;
            --border-radius: 12px; --box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            --input-bg: #fff; --input-focus-border: var(--secondary-color);
            --input-focus-shadow: rgba(142, 45, 226, 0.25);
            --success-bg: #d1e7dd; --success-color: #0f5132; --error-bg: #f8d7da; --error-color: #721c24;
        }
        body {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            min-height: 100vh; display: flex; justify-content: center; align-items: center;
            font-family: 'Poppins', sans-serif; padding: 20px;
        }
        .login-container {
            background-color: #ffffff; padding: 40px 45px; border-radius: var(--border-radius);
            box-shadow: var(--box-shadow); width: 100%; max-width: 450px; /* Login form width */
            animation: fadeInScale 0.6s ease-out forwards; opacity: 0; transform: scale(0.95);
        }
        @keyframes fadeInScale { to { opacity: 1; transform: scale(1); } }
        .form-header {
            font-weight: 700; font-size: 2rem;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; text-fill-color: transparent;
            text-align: center; margin-bottom: 30px; line-height: 1.2;
        }
        .form-label { font-weight: 600; color: var(--text-color); margin-bottom: 8px; font-size: 0.95rem; }
        .form-control {
            border-radius: 8px; padding: 12px 15px; border: 1px solid var(--border-color);
            transition: border-color 0.3s ease, box-shadow 0.3s ease; background-color: var(--input-bg);
            font-size: 1rem;
        }
        .form-control::placeholder { color: #aaa; opacity: 1; }
        .form-control:focus {
            border-color: var(--input-focus-border); box-shadow: 0 0 0 0.25rem var(--input-focus-shadow); outline: none;
        }
        .btn-login {
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color)); border: none;
            padding: 14px 20px; font-size: 1.1rem; font-weight: 600; color: #fff; border-radius: 8px;
            width: 100%; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            letter-spacing: 0.5px; margin-top: 20px; /* Spacing above button */
        }
        .btn-login:hover, .btn-login:focus {
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
            box-shadow: 0 6px 20px rgba(142, 45, 226, 0.4); transform: translateY(-2px); color: #fff;
        }
        .btn-login:active { transform: translateY(0px); box-shadow: 0 3px 10px rgba(142, 45, 226, 0.3); }
        .sub-links { /* Container for links below password */
            display: flex;
            justify-content: flex-end; /* Align forgot password to the right */
            margin-bottom: 1.5rem; /* mb-4 equivalent */
            font-size: 0.9em;
        }
        .sub-links a {
             color: var(--secondary-color); /* Use a theme color */
             text-decoration: none;
        }
         .sub-links a:hover {
             text-decoration: underline;
             color: var(--primary-color);
        }
        .signup-link {
            text-align: center; margin-top: 25px; font-size: 0.95rem; color: #666;
        }
        .signup-link a { color: var(--primary-color); font-weight: 600; text-decoration: none; transition: color 0.3s ease, text-decoration 0.3s ease; }
        .signup-link a:hover { color: var(--secondary-color); text-decoration: underline; }
        .alert { border-radius: 8px; padding: 15px; margin-bottom: 20px; font-size: 0.95rem; }
        .alert-danger { background-color: var(--error-bg); color: var(--error-color); border: 1px solid var(--error-color); }
        .alert-success { background-color: var(--success-bg); color: var(--success-color); border: 1px solid var(--success-color); }
    </style>
</head>
<body>

<div class="login-container">
    <div class="form-header">
  <span>Automatic Calculation of CIE Marks</span><br>
  <span>Portal Login</span>
</div>

    <?php if (!empty($error_message)): ?> <div class="alert alert-danger text-center"><?= htmlspecialchars($error_message) ?></div> <?php endif; ?>
    <?php if (!empty($success_message)): ?> <div class="alert alert-success text-center"><?= htmlspecialchars($success_message) ?></div> <?php endif; ?>

    <form method="post" action="login.php">
        <div class="mb-3">
            <label for="college_uid" class="form-label">College UID</label>
            <input type="text" id="college_uid" name="college_uid" class="form-control" placeholder="Enter your College UID" required value="<?= $uid_value ?>">
        </div>

        <div class="mb-2"> <!-- Reduced margin slightly -->
            <label for="password" class="form-label">Password</label>
            <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
        </div>

        <!-- Forgot Password Link -->
        <div class="sub-links">
             <a href="forgot_password.php">Forgot Password?</a>
        </div>

        <button type="submit" class="btn-login">Login</button>

        <div class="signup-link">
            Need an account? <a href="signup.php">Sign Up here</a>
        </div>
         <!-- REMOVED separate Admin Login link -->
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>