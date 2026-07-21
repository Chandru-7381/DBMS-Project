<?php
require 'includes/db.php'; // Ensure this path is correct
session_start(); // Good practice, even if not immediately used for auth here

// --- Variables for feedback ---
$message = "";
$message_type = ""; // 'success' or 'error'

// --- Default password ---
$default_password_plain = 'acharya@1234'; // Consistent default password

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // --- Retrieve and sanitize form data ---
    $name       = trim($_POST['name'] ?? '');
    $usn        = trim(strtoupper($_POST['usn'] ?? ''));
    $section    = trim(strtoupper($_POST['section'] ?? ''));
    $department = trim($_POST['department'] ?? '');
    $semester   = filter_var($_POST['semester'] ?? '', FILTER_VALIDATE_INT);

    // --- Basic Validation ---
    if (empty($name) || empty($usn) || empty($section) || empty($department)) {
        $message = "Name, USN, Section, and Department are required.";
        $message_type = "error";
    } elseif ($semester === false || $semester < 1 || $semester > 8) {
        $message = "Valid Semester (1-8) is required.";
        $message_type = "error";
    } else {
        // --- Generate Email and Hash Password ---
        $email = strtolower($usn) . "@acharya.ac.in"; // Or your desired domain
        $password_hashed = password_hash($default_password_plain, PASSWORD_DEFAULT);
        $role = 'student';

        // --- Check for existing USN in students table ---
        $stmt_check_usn = $conn->prepare("SELECT id FROM students WHERE usn = ?");
        if (!$stmt_check_usn) {
            $message = "Error preparing USN check: " . $conn->error;
            $message_type = "error";
            error_log("Prepare failed (add_student.php - usn check): " . $conn->error);
        } else {
            $stmt_check_usn->bind_param("s", $usn);
            $stmt_check_usn->execute();
            $result_usn = $stmt_check_usn->get_result();

            if ($result_usn->num_rows > 0) {
                $message = "Failed: A student profile with USN '" . htmlspecialchars($usn) . "' already exists.";
                $message_type = "error";
            } else {
                // --- Proceed with Transaction ---
                $conn->begin_transaction();
                try {
                    // 1. Insert into users table
                    $stmt_user = $conn->prepare("INSERT INTO users (name, email, password, role, department) VALUES (?, ?, ?, ?, ?)");
                    if (!$stmt_user) throw new Exception("Prepare failed (users): " . $conn->error);

                    $stmt_user->bind_param("sssss", $name, $email, $password_hashed, $role, $department);
                    if (!$stmt_user->execute()) {
                         if ($conn->errno == 1062) { throw new Exception("Could not create user. The generated email ('" . htmlspecialchars($email) . "') might already exist."); }
                         else { throw new Exception("Execute failed (users): " . $stmt_user->error); }
                    }
                    $new_user_id = $conn->insert_id;
                    $stmt_user->close();

                    // 2. Insert into students table
                    $stmt_student = $conn->prepare("INSERT INTO students (user_id, usn, section, department, semester) VALUES (?, ?, ?, ?, ?)");
                    if (!$stmt_student) throw new Exception("Prepare failed (students): " . $conn->error);

                    $stmt_student->bind_param("isssi", $new_user_id, $usn, $section, $department, $semester);
                    if (!$stmt_student->execute()) throw new Exception("Execute failed (students): " . $stmt_student->error);
                    $stmt_student->close();

                    // Commit if successful
                    $conn->commit();
                    $message = "Student '" . htmlspecialchars($name) . "' (USN: " . htmlspecialchars($usn) . ") added successfully! Default password is '" . $default_password_plain . "'.";
                    $message_type = "success";

                    // Clear variables after success
                    $name = $usn = $section = $department = $semester = '';

                } catch (Exception $e) {
                    $conn->rollback(); // Rollback on error
                    $message = "Error adding student: " . $e->getMessage();
                    $message_type = "error";
                    error_log("add_student.php Error: " . $e->getMessage());
                } // End Try-Catch
            } // End USN check else
            $stmt_check_usn->close();
        } // End prepare USN check else
    } // End validation else
} // End POST check
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Student</title>
    <style>
        /* Simple styles - adjust as needed */
        body { font-family: sans-serif; padding: 20px; background-color: #f4f4f4; }
        .container { max-width: 600px; margin: auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h2 { text-align: center; color: #333; margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="number"], select {
            width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;
        }
        button {
            background-color: #5cb85c; color: white; padding: 12px 20px; border: none; border-radius: 4px; cursor: pointer; width: 100%; font-size: 16px;
        }
        button:hover { background-color: #4cae4c; }
        .message { padding: 15px; margin-bottom: 20px; border-radius: 4px; font-weight: bold; }
        .message.success { background-color: #dff0d8; color: #3c763d; border: 1px solid #d6e9c6; }
        .message.error { background-color: #f2dede; color: #a94442; border: 1px solid #ebccd1; }
        small { color: #666; font-size: 0.85em; display: block; margin-top: -10px; margin-bottom: 10px;}
    </style>
</head>
<body>

<div class="container">
    <h2>Add New Student Record</h2>

    <?php if (!empty($message)): ?>
        <div class="message <?= $message_type ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="add_student.php">
        <div>
            <label for="name">Student Name:</label>
            <input type="text" id="name" name="name" placeholder="Full Name" required value="<?= htmlspecialchars($name) ?>">
        </div>
        <div>
            <label for="usn">USN:</label>
            <input type="text" id="usn" name="usn" placeholder="University Serial Number" required style="text-transform: uppercase;" value="<?= htmlspecialchars($usn) ?>">
             <small>Login email will be: usn@acharya.ac.in</small>
        </div>
        <div>
            <label for="section">Section:</label>
            <input type="text" id="section" name="section" placeholder="e.g., A" required maxlength="5" style="text-transform: uppercase;" value="<?= htmlspecialchars($section) ?>">
        </div>
         <div>
            <label for="semester">Semester:</label>
            <input type="number" id="semester" name="semester" placeholder="e.g., 4" required min="1" max="8" value="<?= htmlspecialchars($semester) ?>">
             <small>Default password: <?= htmlspecialchars($default_password_plain) ?></small>
        </div>
        <div>
            <label for="department">Department:</label>
             <!-- Use text input as requested -->
             <input type="text" id="department" name="department" placeholder="e.g., Computer Science" required value="<?= htmlspecialchars($department) ?>">
             <!-- Or use a select if preferred:
             <select id="department" name="department" required>
                 <option value="">-- Select Department --</option>
                 <option value="Computer Science" <?= ($department == 'Computer Science') ? 'selected' : ''; ?>>Computer Science</option>
                 <option value="AIML" <?= ($department == 'AIML') ? 'selected' : ''; ?>>AIML</option>
                 <?php // Add more departments ?>
             </select>
             -->
        </div>

        <button type="submit">Add Student</button>
    </form>
</div>

</body>
</html>