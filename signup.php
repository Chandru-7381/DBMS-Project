<?php
require 'includes/db.php'; // Make sure this path is correct
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    // Redirect to appropriate dashboard regardless of current page intention
    header("Location: " . ($_SESSION['role'] == 'faculty' ? 'faculty_dashboard.php' : 'student_dashboard.php'));
    exit;
}

$signup_error = '';
$signup_success = '';

// --- Variables for form repopulation ---
$name       = '';
$college_uid= '';
$email      = '';
// $role is fixed to 'student'
$department = '';
$usn        = ''; // Student only, now optional
$section    = ''; // Student only
$semester   = ''; // Student only
// --- Default password ---
$default_password_plain = 'acharya@1234';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // --- Retrieve and sanitize form data ---
    $name        = trim($_POST['name'] ?? '');
    $college_uid = trim($_POST['college_uid'] ?? '');
    $email       = trim(strtolower($_POST['email'] ?? ''));
    $role        = 'student'; // Fixed role
    $department  = trim($_POST['department'] ?? '');
    $usn         = isset($_POST['usn']) && trim($_POST['usn']) !== '' ? trim(strtoupper($_POST['usn'])) : null;
    $section     = isset($_POST['section']) ? trim(strtoupper($_POST['section'])) : null;
    $semester    = isset($_POST['semester']) ? filter_var($_POST['semester'], FILTER_VALIDATE_INT) : null;


    // --- Validation ---
    if (empty($name) || empty($college_uid) || empty($email) || empty($section) || empty($department)) {
        $signup_error = "Name, College UID, Email, Section, and Department are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $signup_error = "Invalid email format provided.";
    } elseif ($semester === false || $semester < 1 || $semester > 8) {
         $signup_error = "A valid Semester (1-8) is required.";
    }
    // --- End Validation ---


    // --- Database Checks (if basic validation passed) ---
    if (empty($signup_error)) {
        $checks_passed = true; $existing_error = '';
        // 1. Check College UID
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE college_uid = ?");
        if ($stmt_check) { $stmt_check->bind_param("s", $college_uid); $stmt_check->execute(); if ($stmt_check->get_result()->num_rows > 0) { $existing_error = "College UID '".htmlspecialchars($college_uid)."'"; } $stmt_check->close(); }
        else { $existing_error = "DB Error (UID Check)"; error_log("Prepare failed (uid check): ".$conn->error); }
        // 2. Check Email
        if(empty($existing_error)) {
            $stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ?");
            if ($stmt_check) { $stmt_check->bind_param("s", $email); $stmt_check->execute(); if ($stmt_check->get_result()->num_rows > 0) { $existing_error = "Email '".htmlspecialchars($email)."'"; } $stmt_check->close(); }
            else { $existing_error = "DB Error (Email Check)"; error_log("Prepare failed (email check): ".$conn->error); }
        }
        // 3. Check USN (only IF USN was provided)
         if(empty($existing_error) && !empty($usn)) {
            $stmt_check = $conn->prepare("SELECT id FROM students WHERE usn = ?");
             if ($stmt_check) { $stmt_check->bind_param("s", $usn); $stmt_check->execute(); if ($stmt_check->get_result()->num_rows > 0) { $existing_error = "USN '".htmlspecialchars($usn)."'"; } $stmt_check->close(); }
             else { $existing_error = "DB Error (USN Check)"; error_log("Prepare failed (usn check): ".$conn->error); }
        }
        if (!empty($existing_error)) { $signup_error = "Signup Failed: " . $existing_error . " already exists."; $checks_passed = false; }
        else { $checks_passed = true; }
    } else { $checks_passed = false; }
    // --- End Database Checks ---


    // --- Process Signup ---
    if (empty($signup_error) && $checks_passed) {
        $hashed_password = password_hash($default_password_plain, PASSWORD_DEFAULT);
        $conn->begin_transaction();
        try {
            // 1. Insert into users table
            $stmt_user = $conn->prepare("INSERT INTO users (name, email, college_uid, password, role, department, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt_user) throw new Exception("Prepare failed (users insert): " . $conn->error);
            $needs_change = true; // Set flag to true for new student signup
            // Added 'i' for boolean/integer type for the flag and the $needs_change variable
            $stmt_user->bind_param("ssssssi", $name, $email, $college_uid, $hashed_password, $role, $department, $needs_change); // role is fixed 'student'
            if (!$stmt_user->execute()) { if ($conn->errno == 1062) { throw new Exception("Signup failed. College UID or Email might already be registered."); } else { throw new Exception("Execute failed (users insert): " . $stmt_user->error); } }
            $user_id = $conn->insert_id; $stmt_user->close();

            // 2. Insert into students table (always happens now for this page)
            $stmt_student = $conn->prepare("INSERT INTO students (user_id, usn, section, department, semester) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt_student) throw new Exception("Prepare failed (students insert): " . $conn->error);
            $stmt_student->bind_param("isssi", $user_id, $usn, $section, $department, $semester); // $usn might be NULL
            if (!$stmt_student->execute()) { if ($conn->errno == 1062 && !empty($usn)) { throw new Exception("Failed to add profile. USN might already exist.");} else { throw new Exception("Execute failed (students insert): " . $stmt_student->error); } }
            $stmt_student->close();

            $conn->commit();
            $_SESSION['signup_success'] = "Student account created! Login using your College UID. Default pass: '" . $default_password_plain . "'. Change after login.";
            header("Location: login.php"); exit;
        } catch (Exception $e) {
            $conn->rollback(); error_log("Student Signup Transaction Error: " . $e->getMessage());
             if (strpos($e->getMessage(), 'Duplicate entry') !== false || (isset($conn->errno) && $conn->errno == 1062)) { $signup_error = "Signup failed. College UID, Email, or USN (if provided) might already be registered."; }
             else { $signup_error = "Signup failed due to a server error."; }
        }
    } // End process signup
} // End POST check

if (isset($_SESSION['signup_success'])) { $signup_success = $_SESSION['signup_success']; unset($_SESSION['signup_success']); }

// Repopulate variables from POST if validation failed
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($signup_error)) {
    $name = htmlspecialchars($_POST['name'] ?? ''); $college_uid = htmlspecialchars($_POST['college_uid'] ?? ''); $email = htmlspecialchars($_POST['email'] ?? '');
    // $role is always student
    $department = htmlspecialchars($_POST['department'] ?? '');
    $usn = htmlspecialchars($_POST['usn'] ?? ''); $section = htmlspecialchars($_POST['section'] ?? ''); $semester = htmlspecialchars($_POST['semester'] ?? '');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Signup | University Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Copied styles from login.php */
         :root { --primary-color: #4a00e0; --secondary-color: #8e2de2; --border-radius: 12px; --box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15); /* ... other vars */ }
        body { background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; font-family: 'Poppins', sans-serif; padding: 20px; }
        .signup-container { background-color: #ffffff; padding: 40px 45px; border-radius: var(--border-radius); box-shadow: var(--box-shadow); width: 100%; max-width: 500px; animation: fadeInScale 0.6s ease-out forwards; opacity: 0; transform: scale(0.95); }
        @keyframes fadeInScale { to { opacity: 1; transform: scale(1); } }
        .form-header { font-weight: 700; font-size: 2rem; background: linear-gradient(90deg, var(--primary-color), var(--secondary-color)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; text-fill-color: transparent; text-align: center; margin-bottom: 30px; line-height: 1.2; }
        .form-label { font-weight: 600; color: #444; margin-bottom: 8px; font-size: 0.95rem; }
        .form-control, .form-select { border-radius: 8px; padding: 12px 15px; border: 1px solid #dee2e6; transition: border-color 0.3s ease, box-shadow 0.3s ease; background-color: #fff; font-size: 1rem; }
        .form-control::placeholder { color: #aaa; opacity: 1; }
        .form-control:focus, .form-select:focus { border-color: var(--secondary-color); box-shadow: 0 0 0 0.25rem rgba(142, 45, 226, 0.25); outline: none; }
        .form-select { background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e"); background-repeat: no-repeat; background-position: right 0.75rem center; background-size: 16px 12px; appearance: none; -webkit-appearance: none; -moz-appearance: none; }
        .btn-signup { background: linear-gradient(90deg, var(--primary-color), var(--secondary-color)); border: none; padding: 14px 20px; font-size: 1.1rem; font-weight: 600; color: #fff; border-radius: 8px; width: 100%; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); letter-spacing: 0.5px; margin-top: 15px; }
        .btn-signup:hover, .btn-signup:focus { background: linear-gradient(90deg, var(--secondary-color), var(--primary-color)); box-shadow: 0 6px 20px rgba(142, 45, 226, 0.4); transform: translateY(-2px); color: #fff; }
        .btn-signup:active { transform: translateY(0px); box-shadow: 0 3px 10px rgba(142, 45, 226, 0.3); }
        .login-link { text-align: center; margin-top: 25px; font-size: 0.95rem; color: #666; }
        .login-link a { color: var(--primary-color); font-weight: 600; text-decoration: none; transition: color 0.3s ease, text-decoration 0.3s ease; }
        .login-link a:hover { color: var(--secondary-color); text-decoration: underline; }
        .alert { border-radius: 8px; padding: 15px; margin-bottom: 20px; font-size: 0.95rem; }
        .alert-danger { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .alert-success { background-color: #d1e7dd; color: #0f5132; border-color: #badbcc; }
        .form-text { margin-top: .25rem; font-size: .875em; color: #6c757d; }
        /* Remove role-specific field styles as they are not needed */
    </style>
</head>
<body>

<div class="signup-container">
    <div class="form-header">Student Signup</div> <!-- Updated Header -->

    <?php
    if (!empty($signup_error)) { echo "<div class='alert alert-danger text-center'>" . htmlspecialchars($signup_error) . "</div>"; }
    if (!empty($signup_success)) { echo "<div class='alert alert-success text-center'>" . htmlspecialchars($signup_success) . " Redirecting to login...</div>"; }
    ?>

    <?php if (empty($signup_success)): ?>
        <form method="post" action="signup.php" novalidate>
             <div class="mb-3">
                <label for="name" class="form-label">Full Name</label>
                <input type="text" id="name" name="name" class="form-control" placeholder="Enter your full name" required value="<?= htmlspecialchars($name) ?>">
            </div>

            <div class="mb-3">
                <label for="college_uid" class="form-label">College UID (For Login)</label>
                <input type="text" id="college_uid" name="college_uid" class="form-control" placeholder="Enter your official College UID" required value="<?= htmlspecialchars($college_uid) ?>">
            </div>

             <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="you@example.com" required value="<?= htmlspecialchars($email) ?>">
                 <small class="form-text text-muted">Used for communication/recovery.</small>
            </div>

            <div class="mb-3">
                <label for="department" class="form-label">Department</label>
                <input type="text" id="department" name="department" class="form-control" placeholder="Enter your department name" required value="<?= htmlspecialchars($department) ?>">
            </div>

            <!-- Role Selection Removed -->
            <input type="hidden" name="role" value="student"> <!-- Hidden field to pass role -->

            <!-- Student Specific Fields are now always visible for this form -->
            <div class="mb-3" id="usn-field">
                <label for="usn" class="form-label">USN (Optional)</label>
                <input type="text" id="usn" name="usn" class="form-control" placeholder="Enter University Serial Number (Optional)" style="text-transform: uppercase;" value="<?= htmlspecialchars($usn) ?>">
            </div>
            <div class="mb-3" id="section-field">
                <label for="section" class="form-label">Section</label>
                <input type="text" id="section" name="section" class="form-control" placeholder="e.g., A, B1" required maxlength="5" style="text-transform: uppercase;" value="<?= htmlspecialchars($section) ?>">
            </div>
             <div class="mb-3" id="semester-field">
                <label for="semester" class="form-label">Current Semester</label>
                <input type="number" id="semester" name="semester" class="form-control" placeholder="e.g., 4" required min="1" max="8" value="<?= htmlspecialchars($semester) ?>">
             </div>
             <!-- End Student Specific Fields -->

             <p class="form-text text-muted text-center">Default password is '<?= htmlspecialchars($default_password_plain) ?>'. Please change it after your first login.</p>

            <button type="submit" class="btn-signup">Sign Up</button>

            <div class="login-link">
                Already have an account? <a href="login.php">Login here</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<!-- Remove the toggleFields JS function -->
<!-- <script> function toggleFields() { ... } </script> -->
<!-- <script> document.addEventListener('DOMContentLoaded', toggleFields); </script> -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>