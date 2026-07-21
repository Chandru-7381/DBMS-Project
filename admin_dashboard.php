<?php
session_start();
require 'includes/db.php'; // Make sure this path is correct

// --- Database Schema Assumption ---
// users: id, name, email, college_uid, password, role ('admin', 'faculty', 'student'), department, must_change_password, created_at, updated_at
// subjects: id, name, code (UNIQUE), type ('Theory', 'IPCC', 'Lab'), created_by_faculty_id (FK to users.id, NULLABLE), created_at, updated_at
// faculty_subjects: id, faculty_id (FK to users.id), subject_id (FK to subjects.id), assigned_at (DEFAULT CURRENT_TIMESTAMP) - UNIQUE(faculty_id, subject_id)

// --- Authentication: Ensure user is Admin ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    // Redirect based on role if logged in, otherwise to admin login
    if (isset($_SESSION['role'])) {
        header("Location: " . ($_SESSION['role'] == 'faculty' ? 'faculty_dashboard.php' : ($_SESSION['role'] == 'student' ? 'student_dashboard.php' : 'admin_login.php')));
    } else {
        header("Location: admin_login.php");
    }
    exit;
}

$admin_user_id = $_SESSION['user_id'];
$message = ""; $message_type = "";
$default_password_plain = 'acharya@1234'; // Consider making this configurable

// --- ACTION: Add New Faculty ---
if (isset($_POST['add_faculty'])) {
    $name = trim($_POST['name'] ?? '');
    $college_uid = trim($_POST['college_uid'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $department = trim($_POST['department'] ?? '');
    $role = 'faculty';
    $hashed_password = password_hash($default_password_plain, PASSWORD_DEFAULT);

    // Validation
    if (empty($name) || empty($college_uid) || empty($email) || empty($department)) {
        $message = "All fields are required to add a faculty member.";
        $message_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format provided.";
        $message_type = "error";
    } else {
        // Check for existing user with the same College UID or Email
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE college_uid = ? OR email = ?");
        if ($stmt_check) {
            $stmt_check->bind_param("ss", $college_uid, $email);
            $stmt_check->execute();
            $result = $stmt_check->get_result();
            if ($result->num_rows > 0) {
                $message = "Faculty with this College UID or Email already exists.";
                $message_type = "error";
            }
            $stmt_check->close();
        } else {
            $message = "Database error checking for existing user.";
            $message_type = "error";
            error_log("Admin Add Faculty - Check Error: " . $conn->error);
        }

        // Proceed if no duplicates found
        if (empty($message)) {
            $stmt_add = $conn->prepare("INSERT INTO users (name, email, college_uid, password, role, department, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt_add) {
                $needs_change = 1; // Set must_change_password to true (1)
                $stmt_add->bind_param("ssssssi", $name, $email, $college_uid, $hashed_password, $role, $department, $needs_change);
                if ($stmt_add->execute()) {
                    $message = "Faculty '" . htmlspecialchars($name) . "' added successfully. Default password: '" . htmlspecialchars($default_password_plain) . "'";
                    $message_type = "success";
                } else {
                    $message = "Failed to add faculty: " . $stmt_add->error;
                    $message_type = "error";
                    error_log("Admin Add Faculty - Insert Error: " . $stmt_add->error);
                }
                $stmt_add->close();
            } else {
                $message = "Database error preparing faculty insert statement.";
                $message_type = "error";
                error_log("Admin Add Faculty - Prepare Error: " . $conn->error);
            }
        }
    }
}

// --- ACTION: Add New Subject AND Assign to Faculty (Admin creates the subject) ---
if (isset($_POST['add_and_assign_subject'])) {
    $sub_name = trim($_POST['subject_name'] ?? '');
    $sub_code = trim($_POST['subject_code'] ?? '');
    $sub_type = $_POST['subject_type'] ?? '';
    $faculty_id_to_assign = filter_var($_POST['faculty_id_to_assign'] ?? null, FILTER_VALIDATE_INT);

    // Validation
    if (empty($sub_name) || empty($sub_code) || empty($sub_type) || !$faculty_id_to_assign) {
        $message = "Subject Name, Code, Type, and selected Faculty are required."; $message_type = "error";
    } elseif (!in_array($sub_type, ['Theory', 'IPCC', 'Lab'])) {
         $message = "Invalid subject type selected."; $message_type = "error";
    } else {
        // Check for duplicate subject code first
        $stmt_check_code = $conn->prepare("SELECT id FROM subjects WHERE code = ?");
        if ($stmt_check_code) {
            $stmt_check_code->bind_param("s", $sub_code);
            $stmt_check_code->execute();
            if ($stmt_check_code->get_result()->num_rows > 0) {
                $message = "Error: Subject Code '" . htmlspecialchars($sub_code) . "' already exists.";
                $message_type = "error";
            }
            $stmt_check_code->close();
        } else {
            $message = "Database error checking subject code."; $message_type = "error";
            error_log("Admin Add/Assign Subject - Check Code Error: " . $conn->error);
        }

        if (empty($message)) { // Proceed if code is unique
            $conn->begin_transaction();
            try {
                // 1. Insert the new subject (Admin created, so created_by_faculty_id is NULL)
                // Ensure your 'subjects' table has 'created_by_faculty_id' column allowing NULLs
                $stmt_add_subj = $conn->prepare("INSERT INTO subjects (name, code, type, created_by_faculty_id) VALUES (?, ?, ?, NULL)");
                if (!$stmt_add_subj) { throw new Exception("Prepare failed (subjects): " . $conn->error); }
                $stmt_add_subj->bind_param("sss", $sub_name, $sub_code, $sub_type);
                if (!$stmt_add_subj->execute()) { throw new Exception("Execute failed (subjects): " . $stmt_add_subj->error); }
                $new_subject_id = $conn->insert_id;
                $stmt_add_subj->close();

                if ($new_subject_id <= 0) { throw new Exception("Failed to get new subject ID."); }

                // 2. Assign the new subject to the selected faculty
                $stmt_assign = $conn->prepare("INSERT INTO faculty_subjects (faculty_id, subject_id) VALUES (?, ?)");
                 if (!$stmt_assign) { throw new Exception("Prepare failed (faculty_subjects): " . $conn->error); }
                 $stmt_assign->bind_param("ii", $faculty_id_to_assign, $new_subject_id);
                 if (!$stmt_assign->execute()) {
                     // Check for duplicate assignment error (errno 1062 for MySQL Unique constraint)
                     if ($conn->errno == 1062) {
                        // This *shouldn't* happen if the subject ID is truly new, but good practice to check.
                        throw new Exception("Assignment failed: This faculty is already assigned this subject (unexpected error).");
                     } else {
                         throw new Exception("Execute failed (faculty_subjects): " . $stmt_assign->error);
                     }
                 }
                 $stmt_assign->close();

                 // If both succeed
                 $conn->commit();
                 $message = "Subject '" . htmlspecialchars($sub_name) . "' added and assigned successfully!";
                 $message_type = "success";

            } catch (Exception $e) {
                $conn->rollback();
                $message = "Error adding/assigning subject: " . $e->getMessage();
                $message_type = "error";
                error_log("Admin Add/Assign Subject Transaction Error: " . $e->getMessage());
            }
        }
    }
}

// --- ACTION: Assign EXISTING Subject to Faculty ---
if (isset($_POST['assign_subject_to_faculty'])) {
    $faculty_id_assign = filter_var($_POST['faculty_id_assign'] ?? null, FILTER_VALIDATE_INT);
    $subject_id_assign = filter_var($_POST['subject_id_assign'] ?? null, FILTER_VALIDATE_INT);

    if (!$faculty_id_assign || !$subject_id_assign) {
        $message = "Invalid Faculty or Subject selected for assignment.";
        $message_type = "error";
    } else {
        // Check if this assignment already exists
        $stmt_check = $conn->prepare("SELECT id FROM faculty_subjects WHERE faculty_id = ? AND subject_id = ?");
        if ($stmt_check) {
            $stmt_check->bind_param("ii", $faculty_id_assign, $subject_id_assign);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $message = "This subject is already assigned to this faculty member.";
                $message_type = "info"; // Use 'info' as it's not an error, just existing state
            }
            $stmt_check->close();
        } else {
            $message = "Database error checking existing assignment.";
            $message_type = "error";
            error_log("Admin Assign Existing - Check Error: " . $conn->error);
        }

        // If the assignment doesn't exist, proceed to add it
        if (empty($message) || $message_type !== "info") { // Also check message_type in case DB error occurred but message wasn't cleared
             if ($message_type !== "error") { // Only proceed if no DB error occurred during check
                $stmt_assign = $conn->prepare("INSERT INTO faculty_subjects (faculty_id, subject_id) VALUES (?, ?)");
                if ($stmt_assign) {
                    $stmt_assign->bind_param("ii", $faculty_id_assign, $subject_id_assign);
                    if ($stmt_assign->execute()) {
                        $message = "Subject assigned successfully.";
                        $message_type = "success";
                    } else {
                        // Check for specific errors like foreign key constraints if needed
                         if ($conn->errno == 1062) { // Duplicate entry
                             $message = "Assignment failed: This faculty is already assigned this subject.";
                             $message_type = "error"; // Treat duplicate attempt as error here
                         } else {
                             $message = "Failed to assign subject: " . $stmt_assign->error;
                             $message_type = "error";
                             error_log("Admin Assign Existing - Insert Error: " . $stmt_assign->error);
                         }
                    }
                    $stmt_assign->close();
                } else {
                    $message = "Database error preparing assignment statement.";
                    $message_type = "error";
                    error_log("Admin Assign Existing - Prepare Error: " . $conn->error);
                }
            }
        }
    }
}

// --- ACTION: Remove Subject Assignment from a Faculty ---
if (isset($_POST['remove_assignment'])) {
    $assignment_id = filter_var($_POST['assignment_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$assignment_id) {
        $message = "Invalid assignment ID provided for removal.";
        $message_type = "error";
    } else {
        // This action ONLY removes the link in faculty_subjects.
        // The subject itself remains in the subjects table.
        $stmt_remove = $conn->prepare("DELETE FROM faculty_subjects WHERE id = ?");
        if ($stmt_remove) {
            $stmt_remove->bind_param("i", $assignment_id);
            if ($stmt_remove->execute()) {
                if ($stmt_remove->affected_rows > 0) {
                    $message = "Assignment removed successfully. The subject can be reassigned.";
                    $message_type = "success";
                } else {
                    $message = "Assignment not found or already removed.";
                    $message_type = "info";
                }
            } else {
                $message = "Error removing assignment: " . $stmt_remove->error;
                $message_type = "error";
                error_log("Admin Remove Assignment - Execute Error: " . $stmt_remove->error);
            }
            $stmt_remove->close();
        } else {
            $message = "Database error preparing assignment removal.";
            $message_type = "error";
            error_log("Admin Remove Assignment - Prepare Error: " . $conn->error);
        }
    }
}


// --- DATA FETCHING for Display ---
$faculty_list = [];
$subjects_list = []; // All subjects available for admin assignment
$assignments_list = [];

// Fetch Faculty
$stmt_fac = $conn->prepare("SELECT id, name, college_uid, email, department FROM users WHERE role = 'faculty' ORDER BY name");
if ($stmt_fac) {
    $stmt_fac->execute();
    $result = $stmt_fac->get_result();
    while ($row = $result->fetch_assoc()) { $faculty_list[] = $row; }
    $stmt_fac->close();
} else { error_log("Error fetching faculty list: " . $conn->error); }

// Fetch ALL Subjects (Admin can assign any subject, regardless of creator)
$stmt_sub = $conn->prepare("SELECT id, name, code, type, created_by_faculty_id FROM subjects ORDER BY name");
if ($stmt_sub) {
    $stmt_sub->execute();
    $result = $stmt_sub->get_result();
    while ($row = $result->fetch_assoc()) { $subjects_list[] = $row; }
    $stmt_sub->close();
} else { error_log("Error fetching subjects list: " . $conn->error); }


// Fetch Current Assignments with creator info (optional but potentially useful)
$sql_assignments = "
    SELECT
        fs.id as assignment_id,
        u.id as faculty_id,
        u.name as faculty_name,
        u.college_uid,
        s.id as subject_id,
        s.name as subject_name,
        s.code as subject_code,
        s.type as subject_type,
        creator.name as creator_name -- Get the name of the creator
    FROM faculty_subjects fs
    JOIN users u ON fs.faculty_id = u.id
    JOIN subjects s ON fs.subject_id = s.id
    LEFT JOIN users creator ON s.created_by_faculty_id = creator.id -- Left join in case admin (NULL) created it
    ORDER BY u.name, s.name";

$res_assign = $conn->query($sql_assignments); // Using query for simplicity here, prepared statement better if filtering needed
if ($res_assign) {
    while ($row = $res_assign->fetch_assoc()) {
        // If creator.name is NULL, it means admin created it
        $row['creator_display'] = $row['creator_name'] ?? 'Admin';
        $assignments_list[] = $row;
    }
    $res_assign->free();
} else {
    error_log("Error fetching assignments list: " . $conn->error);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Faculty & Subjects</title>
    <!-- Include CSS/JS dependencies (jQuery, Select2, FontAwesome, Poppins Font, Bootstrap CSS/JS) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Bootstrap CSS - Optional but used for grid below -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* --- Reusing faculty dashboard styles --- */
        :root { --primary-color: #4a00e0; --secondary-color: #8e2de2; --accent-color: #00bcd4; --bg-color: #f4f7fc; --card-bg: #ffffff; --text-color: #333; --text-muted: #6c757d; --border-color: #e0e0e0; --success-color: #198754; --error-color: #dc3545; --info-color: #0dcaf0; --warning-color: #ffc107; --success-bg: #d1e7dd; --error-bg: #f8d7da; --info-bg: #cff4fc; --warning-bg: #fff3cd; --border-radius: 8px; --box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); --input-bg: #fff; --input-focus-border: var(--secondary-color); --input-focus-shadow: rgba(142, 45, 226, 0.2); }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; padding: 0; line-height: 1.6; }
        .admin-container { max-width: 1300px; margin: 30px auto; padding: 25px; }
        .admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding: 15px 30px; background-color: var(--card-bg); border-radius: var(--border-radius); box-shadow: var(--box-shadow); }
        .admin-header h2 { color: var(--primary-color); margin: 0; font-weight: 600; }
        .logout-btn { text-decoration: none; padding: 8px 15px; background-color: var(--error-color); color: white; border-radius: 5px; font-size: 0.9rem; transition: background-color 0.3s ease; }
        .logout-btn:hover { background-color: #c82333; }
        .admin-section { background: var(--card-bg); padding: 25px 30px; border-radius: var(--border-radius); box-shadow: var(--box-shadow); margin-bottom: 30px; border: 1px solid var(--border-color); }
        .admin-section h3 { color: var(--secondary-color); border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 20px; font-weight: 600; margin-top: 0; }
        label, .form-label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 0.9rem; color: #555; }
        input[type="text"], input[type="email"], input[type="number"], select, .select2-container .select2-selection--single { width: 100%; padding: 10px 12px; margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 5px; font-size: 0.95rem; box-sizing: border-box; transition: border-color 0.3s ease, box-shadow 0.3s ease; height: auto !important; background-color: var(--input-bg); }
        /* Ensure selects defined by Bootstrap also get styled */
        select.form-select { height: calc(1.5em + .75rem + 9px) !important; padding: 10px 12px; font-size: 0.95rem; margin-bottom: 15px; }
        .select2-container .select2-selection--single { height: calc(1.5em + .75rem + 9px) !important; display: flex; align-items: center; } /* Adjusted Select2 height */
        input:focus, select:focus, .select2-container--focus .select2-selection--single { border-color: var(--input-focus-border); box-shadow: 0 0 0 0.2rem var(--input-focus-shadow); outline: none; }
        .btn { padding: 10px 18px; border: none; border-radius: 5px; cursor: pointer; font-size: 0.95rem; font-weight: 500; transition: all 0.3s ease; margin-right: 5px; margin-top: 5px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-sm { padding: 6px 12px; font-size: 0.85rem; gap: 5px; }
        .btn-primary { background: linear-gradient(to right, var(--primary-color), var(--secondary-color)); color: white; border: 1px solid var(--primary-color); } .btn-primary:hover { background: linear-gradient(to right, var(--secondary-color), var(--primary-color)); transform: translateY(-1px); box-shadow: 0 4px 10px rgba(74, 0, 224, 0.2); }
        .btn-success { background-color: var(--success-color); color: white; border: 1px solid var(--success-color); } .btn-success:hover { background-color: #157347; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(25, 135, 84, 0.2); border-color: #146c43; }
        .btn-secondary { background-color: var(--accent-color); color: white; border: 1px solid var(--accent-color); } .btn-secondary:hover { background-color: #00a5bb; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0, 188, 212, 0.2); border-color: #008a9e; }
        .btn-danger { background-color: var(--error-color); color: white; border: 1px solid var(--error-color); } .btn-danger:hover { background-color: #c82333; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(220, 53, 69, 0.2); border-color: #b02a37; }
        .table-responsive { overflow-x: auto; margin-top: 20px; }
        .table { width: 100%; border-collapse: collapse; background-color: var(--card-bg); }
        .table th, .table td { padding: 12px 15px; border-bottom: 1px solid var(--border-color); text-align: left; font-size: 0.9rem; vertical-align: middle; white-space: nowrap; }
        .table thead th { background-color: #f8f9fa; color: var(--primary-color); font-weight: 600; border-bottom-width: 2px; }
        .table-hover tbody tr:hover { background-color: #eef2f7; }
        .message-area { padding: 15px 20px; margin: 20px 0; border-radius: var(--border-radius); font-weight: 500; display: none; border: 1px solid transparent; animation: fadeIn 0.5s ease; }
        .message-area.success { background-color: var(--success-bg); color: var(--success-color); border-color: var(--success-color); display: block; }
        .message-area.error { background-color: var(--error-bg); color: var(--error-color); border-color: var(--error-color); display: block; }
        .message-area.info { background-color: var(--info-bg); color: var(--info-color); border-color: var(--info-color); display: block; }
        .select2-container { width: 100% !important; margin-bottom: 15px; }
        .select2-container--default .select2-selection--single { border: 1px solid var(--border-color); border-radius: 5px; height: calc(1.5em + .75rem + 9px) !important; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: calc(1.5em + .75rem + 5px); padding-left: 12px; color: var(--text-color); }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: calc(1.5em + .75rem + 5px); right: 8px; }
        .select2-container--default .select2-selection--single .select2-selection__placeholder { color: #6c757d; }
        .select2-container--default.select2-container--open .select2-selection--single { border-color: var(--input-focus-border); box-shadow: 0 0 0 0.2rem var(--input-focus-shadow); }
        .select2-dropdown { border: 1px solid var(--input-focus-border); box-shadow: 0 6px 12px rgba(0,0,0,0.1); border-radius: 5px; margin-top: 2px; }
        .select2-search--dropdown .select2-search__field { border: 1px solid var(--border-color); border-radius: 4px; padding: 8px 10px; }
        .select2-results__option { padding: 8px 12px; } .select2-results__option--highlighted[aria-selected] { background-color: var(--primary-color); color: white; }
        .form-text { margin-top: .25rem; font-size: .875em; color: #6c757d; }
         /* Use Bootstrap grid classes directly */
        .mb-3 { margin-bottom: 1rem !important; }
        .align-self-end { align-self: flex-end !important; }
        .w-100 { width: 100% !important; }
        .text-center { text-align: center !important; }
        .text-muted { color: #6c757d !important; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    </style>
</head>
<body>
<div class="admin-container">
    <div class="admin-header">
        <h2><i class="fas fa-user-shield"></i> Admin Dashboard</h2>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <?php if (!empty($message)): ?>
        <div class="message-area <?= htmlspecialchars($message_type) ?>"> <?= htmlspecialchars($message) ?> </div>
    <?php endif; ?>

    <!-- Section to Add Faculty -->
    <div class="admin-section">
        <h3><i class="fas fa-user-plus"></i> Add New Faculty</h3>
        <form method="post">
            <input type="hidden" name="add_faculty" value="1">
            <div class="row g-3">
                <div class="col-md-6 mb-3">
                    <label for="fac_name" class="form-label">Faculty Name</label>
                    <input type="text" id="fac_name" name="name" required class="form-control">
                </div>
                 <div class="col-md-6 mb-3">
                    <label for="fac_uid" class="form-label">College UID (For Login)</label>
                    <input type="text" id="fac_uid" name="college_uid" required class="form-control">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="fac_email" class="form-label">Email</label>
                    <input type="email" id="fac_email" name="email" required class="form-control">
                </div>
                 <div class="col-md-6 mb-3">
                    <label for="fac_dept" class="form-label">Department</label>
                    <input type="text" id="fac_dept" name="department" required class="form-control" placeholder="e.g., Computer Science">
                 </div>
                 <div class="col-12">
                     <p class="form-text">Default password will be '<?= htmlspecialchars($default_password_plain) ?>'. Faculty will be prompted to change it on first login.</p>
                     <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Faculty</button>
                 </div>
            </div>
        </form>
    </div>

    <!-- Section: Add New Subject AND Assign to Faculty -->
    <div class="admin-section">
        <h3><i class="fas fa-book-medical"></i> Add New Subject & Assign</h3>
        <form method="post">
            <input type="hidden" name="add_and_assign_subject" value="1">
            <div class="row g-3 mb-3"> <!-- Subject Details Row -->
                 <div class="col-md-4">
                    <label for="new_subject_name" class="form-label">New Subject Name</label>
                    <input type="text" id="new_subject_name" name="subject_name" required class="form-control">
                 </div>
                 <div class="col-md-3">
                    <label for="new_subject_code" class="form-label">New Subject Code</label>
                    <input type="text" id="new_subject_code" name="subject_code" required class="form-control">
                 </div>
                 <div class="col-md-3">
                     <label for="new_subject_type" class="form-label">New Subject Type</label>
                     <select id="new_subject_type" name="subject_type" required class="form-select">
                         <option value="" selected disabled>-- Select Type --</option>
                         <option value="Theory">Theory</option>
                         <option value="IPCC">IPCC</option>
                         <option value="Lab">Lab</option>
                     </select>
                 </div>
            </div>
            <div class="row g-3"> <!-- Assignment Row -->
                <div class="col-md-8">
                     <label for="faculty_select_add_assign" class="form-label">Assign to Faculty</label>
                     <select id="faculty_select_add_assign" name="faculty_id_to_assign" class="select2-init" required data-placeholder="-- Select Faculty --">
                         <option value=""></option> <!-- Required for placeholder -->
                         <?php foreach($faculty_list as $fac): ?>
                            <option value="<?= $fac['id'] ?>"><?= htmlspecialchars($fac['name']) ?> (<?= htmlspecialchars($fac['college_uid']) ?>)</option>
                         <?php endforeach; ?>
                     </select>
                 </div>
                  <div class="col-md-4 align-self-end" style="padding-bottom: 15px;"> <!-- Align button vertically -->
                      <button type="submit" class="btn btn-success w-100"><i class="fas fa-plus-circle"></i> Add Subject & Assign</button>
                  </div>
            </div>
             <p class="form-text mt-2">This action creates a new subject (marked as Admin-created) and immediately assigns it to the selected faculty.</p>
        </form>
    </div>

     <!-- Section to Assign EXISTING Subject to Faculty -->
     <div class="admin-section">
        <h3><i class="fas fa-link"></i> Assign Existing Subject</h3>
         <form method="post">
             <input type="hidden" name="assign_subject_to_faculty" value="1">
             <div class="row g-3">
                 <div class="col-md-5">
                     <label for="faculty_select_assign_existing" class="form-label">Select Faculty</label>
                     <select id="faculty_select_assign_existing" name="faculty_id_assign" class="select2-init" required data-placeholder="-- Select Faculty --">
                         <option value=""></option>
                         <?php foreach($faculty_list as $fac): ?>
                            <option value="<?= $fac['id'] ?>"><?= htmlspecialchars($fac['name']) ?> (<?= htmlspecialchars($fac['college_uid']) ?>)</option>
                         <?php endforeach; ?>
                     </select>
                 </div>
                  <div class="col-md-5">
                     <label for="subject_select_assign_existing" class="form-label">Select Existing Subject</label>
                     <select id="subject_select_assign_existing" name="subject_id_assign" class="select2-init" required data-placeholder="-- Select Subject --">
                         <option value=""></option>
                           <?php foreach($subjects_list as $sub): ?>
                            <option value="<?= $sub['id'] ?>">
                                <?= htmlspecialchars($sub['name']) ?> (<?= htmlspecialchars($sub['code']) ?>)
                                <?= $sub['created_by_faculty_id'] ? '[Faculty Created]' : '[Admin Created]' ?>
                            </option>
                         <?php endforeach; ?>
                     </select>
                 </div>
                 <div class="col-md-2 align-self-end" style="padding-bottom: 15px;">
                      <button type="submit" class="btn btn-secondary w-100"><i class="fas fa-check"></i> Assign</button>
                 </div>
             </div>
              <p class="form-text mt-2">Use this to assign any subject (Admin or Faculty created) to a faculty member. This is how you reassign subjects if a faculty leaves.</p>
         </form>
     </div>

      <!-- Section to View Current Assignments -->
      <div class="admin-section">
          <h3><i class="fas fa-clipboard-list"></i> Current Faculty-Subject Assignments</h3>
          <div class="table-responsive">
              <table class="table table-hover">
                  <thead>
                      <tr>
                          <th>Faculty Name</th>
                          <th>Faculty UID</th>
                          <th>Subject Name</th>
                          <th>Subject Code</th>
                          <th>Subject Type</th>
                          <th>Subject Creator</th>
                          <th>Action</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php if (empty($assignments_list)): ?>
                          <tr><td colspan="7" class="text-center text-muted">No assignments found.</td></tr>
                      <?php else: ?>
                          <?php foreach($assignments_list as $assign): ?>
                              <tr>
                                  <td><?= htmlspecialchars($assign['faculty_name']) ?></td>
                                  <td><?= htmlspecialchars($assign['college_uid']) ?></td>
                                  <td><?= htmlspecialchars($assign['subject_name']) ?></td>
                                  <td><?= htmlspecialchars($assign['subject_code']) ?></td>
                                  <td><?= htmlspecialchars($assign['subject_type']) ?></td>
                                  <td><?= htmlspecialchars($assign['creator_display']) ?></td>
                                  <td>
                                      <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to remove this assignment? The subject itself will NOT be deleted.');">
                                          <input type="hidden" name="assignment_id" value="<?= $assign['assignment_id'] ?>">
                                          <button type="submit" name="remove_assignment" value="1" class="btn btn-danger btn-sm" title="Remove Assignment">
                                              <i class="fas fa-times"></i> Remove
                                          </button>
                                      </form>
                                  </td>
                              </tr>
                          <?php endforeach; ?>
                      <?php endif; ?>
                  </tbody>
              </table>
          </div>
           <p class="form-text mt-2">Removing an assignment only breaks the link between the faculty and the subject. The subject remains in the system and can be reassigned.</p>
      </div>

</div> <!-- End Admin Container -->

<script>
    $(document).ready(function() {
        // Initialize all Select2 elements with the class 'select2-init'
        $('.select2-init').select2({
            placeholder: $(this).data('placeholder') || "-- Select --", // Use data-placeholder attribute
            allowClear: true, // Adds a small 'x' to clear the selection
            width: '100%' // Ensures Select2 takes full width of its container
         });

         // Auto-hide messages after a delay
         if ($('.message-area').length && $('.message-area').is(':visible')) {
            setTimeout(function() {
                $('.message-area').fadeOut('slow');
            }, 7000); // Increased delay to 7 seconds
         }
    });
</script>
<!-- Bootstrap Bundle JS (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>