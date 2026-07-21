<?php
session_start();
require 'includes/db.php'; // Make sure this path is correct

// --- Authentication & Role Check ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['faculty', 'admin'])) {
     header("Location: login.php");
     exit;
}

$logged_in_user_id = $_SESSION['user_id'];
$logged_in_role = $_SESSION['role'];
$is_admin = ($logged_in_role == 'admin');
// Correctly assign logged_in_faculty_id ONLY if the role is faculty
$logged_in_faculty_id = ($logged_in_role == 'faculty') ? $logged_in_user_id : null;

// --- Fetch User Details (Faculty or Admin) ---
$user_info = null;
$faculty_info = null; // Initialize faculty_info (might be the same as user_info if role is faculty)
$stmt_user_details = $conn->prepare("SELECT id, name, email, college_uid, department, role FROM users WHERE id = ?");
if ($stmt_user_details) {
    $stmt_user_details->bind_param("i", $logged_in_user_id);
    $stmt_user_details->execute();
    $result_user_details = $stmt_user_details->get_result();
    if ($result_user_details->num_rows === 1) {
        $user_info = $result_user_details->fetch_assoc();
        // Assign to faculty_info specifically if the user is a faculty member
        if($user_info['role'] == 'faculty'){ $faculty_info = $user_info; }
    } else {
        // If the user ID from the session isn't found, invalidate the session.
        error_log("User ID $logged_in_user_id from session not found in database.");
        session_destroy(); header("Location: login.php?error=session_invalid"); exit;
    }
    $stmt_user_details->close();
} else { error_log("Failed to prepare statement to fetch user details: " . $conn->error); die("Error retrieving user information."); }


// --- Determine Permissions and Accessible Departments ---
$viewable_departments = []; // Departments the user can see students FROM (for filtering/listing)
$filter_departments_display = []; // Departments to show as filter buttons
$can_manage_students = false; // Can this user manage student profiles?
$can_assign_subjects = false; // Can this user use the assign subject feature?
$faculty_dept_for_auth = $user_info['department'] ?? null; // User's own department for auth checks

if ($is_admin) {
    $can_manage_students = true; // Admins can manage all students
    $can_assign_subjects = true; // Admins can assign subjects (faculty_id will be NULL)
    // Fetch all distinct non-empty departments that have students for filtering
    $res_depts = $conn->query("SELECT DISTINCT department FROM students WHERE department IS NOT NULL AND department != '' ORDER BY department");
    if($res_depts){
        while($dept_row = $res_depts->fetch_assoc()){
             if(!empty(trim($dept_row['department']))) { // Ensure not just whitespace
                 $viewable_departments[] = trim($dept_row['department']);
                 $filter_departments_display[] = trim($dept_row['department']);
             }
        }
        $res_depts->free();
        $filter_departments_display = array_unique($filter_departments_display); // Remove duplicates just in case
        sort($filter_departments_display); // Sort alphabetically
    }
     // Add Admin's own department if they have one and it's not already listed
     if (isset($user_info['department']) && !empty(trim($user_info['department'])) && !in_array(trim($user_info['department']), $filter_departments_display)) {
        $filter_departments_display[] = trim($user_info['department']);
        sort($filter_departments_display);
    }

} elseif ($logged_in_role == 'faculty' && isset($user_info['department']) && !empty(trim($user_info['department']))) {
    // Faculty can manage students ONLY in their own department.
    $dept = trim($user_info['department']);
    $can_manage_students = true;
    $can_assign_subjects = true; // Faculty can assign subjects (faculty_id will be theirs)
    $viewable_departments[] = $dept; // Can only view their own dept
    $filter_departments_display[] = $dept; // Only filter by their own department
}
// --- End Permission Determination ---


$message = ""; $message_type = ""; $submitted_marks_html = "";
$default_password_plain = 'acharya@1234'; // Define default password

// --- PHP Logic for CRUD Operations ---

// ✅ Add Subject
if (isset($_POST['add_subject'])) {
    $sub_name = trim($_POST['subject_name']);
    $sub_code = trim($_POST['subject_code']);
    $sub_type = $_POST['subject_type'];

    if (empty($sub_name) || empty($sub_code) || empty($sub_type)) {
         $message = "Subject Name, Code, and Type are required."; $message_type = "error";
    } else {
        $stmt = $conn->prepare("INSERT INTO subjects (name, code, type, created_by_faculty_id) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $creator_id_to_insert = $logged_in_faculty_id;
            $stmt->bind_param("sssi", $sub_name, $sub_code, $sub_type, $creator_id_to_insert);
            if ($stmt->execute()) { $message = "Subject added successfully!"; $message_type = "success"; }
            else {
                 if ($stmt->errno == 1062) { $message = "Error adding subject: Code '".htmlspecialchars($sub_code)."' already exists."; }
                 else { $message = "Error adding subject: " . $stmt->error; }
                $message_type = "error";
            }
            $stmt->close();
        } else { $message = "Error preparing statement: " . $conn->error; $message_type = "error"; }
    }
}

// ✅ Edit Subject
if (isset($_POST['edit_subject'])) {
    $subject_id = $_POST['subject_id']; $sub_name = trim($_POST['subject_name']); $sub_code = trim($_POST['subject_code']); $sub_type = $_POST['subject_type'];

    if (empty($sub_name) || empty($sub_code) || empty($sub_type) || empty($subject_id)) {
         $message = "All fields required for updating."; $message_type = "error";
    } else {
        $can_edit_subject = false;
        $stmt_check_owner = $conn->prepare("SELECT created_by_faculty_id FROM subjects WHERE id = ?");
        if ($stmt_check_owner) {
            $stmt_check_owner->bind_param("i", $subject_id); $stmt_check_owner->execute(); $result_owner = $stmt_check_owner->get_result();
            if ($subject_owner = $result_owner->fetch_assoc()) {
                if ($is_admin || ($subject_owner['created_by_faculty_id'] !== null && $subject_owner['created_by_faculty_id'] == $logged_in_faculty_id) ) {
                   $can_edit_subject = true;
                }
            } $stmt_check_owner->close();
        } else { error_log("Failed prepare subject owner check: " . $conn->error); }

        if (!$can_edit_subject) { $message = "Authorization Error: Cannot edit this subject."; $message_type = "error"; }
        else {
            $stmt = $conn->prepare("UPDATE subjects SET name = ?, code = ?, type = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("sssi", $sub_name, $sub_code, $sub_type, $subject_id);
                if ($stmt->execute()) { $message = "Subject updated successfully!"; $message_type = "success"; }
                else {
                     if ($stmt->errno == 1062) { $message = "Error: Code '".htmlspecialchars($sub_code)."' exists."; }
                     else { $message = "Error updating subject: " . $stmt->error; error_log("Update Subj Err: ".$stmt->error); }
                    $message_type = "error";
                } $stmt->close();
            } else { $message = "Error preparing update: " . $conn->error; $message_type = "error"; error_log("Prep Update Subj Err: ".$conn->error); }
        }
     }
}

// ✅ Delete Subject
if (isset($_POST['delete_subject'])) {
    $subject_id = $_POST['subject_id'];
    if (empty($subject_id)) { $message = "Subject ID missing."; $message_type = "error"; }
    else {
        $can_delete_subject = false;
        $stmt_check_owner = $conn->prepare("SELECT created_by_faculty_id FROM subjects WHERE id = ?");
        if ($stmt_check_owner) {
            $stmt_check_owner->bind_param("i", $subject_id); $stmt_check_owner->execute(); $result_owner = $stmt_check_owner->get_result();
            if ($subject_owner = $result_owner->fetch_assoc()) {
                if ($is_admin || ($subject_owner['created_by_faculty_id'] !== null && $subject_owner['created_by_faculty_id'] == $logged_in_faculty_id)) {
                   $can_delete_subject = true;
                }
            } $stmt_check_owner->close();
        } else { error_log("Fail prep check owner delete: " . $conn->error); }

        if (!$can_delete_subject) { $message = "Auth Error: Cannot delete this subject."; $message_type = "error"; }
        else {
            $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
             if ($stmt) {
                 $stmt->bind_param("i", $subject_id);
                 if ($stmt->execute()) {
                     if ($stmt->affected_rows > 0) { $message = "Subject deleted."; $message_type = "success"; }
                     else { $message = "Subject not found."; $message_type = "info"; }
                 } else {
                      if ($stmt->errno == 1451) { $message = "Cannot delete: Marks recorded."; }
                      else { $message = "Error deleting: " . $stmt->error; error_log("Del Subj Err: ".$stmt->error); }
                     $message_type = "error";
                 } $stmt->close();
             } else { $message = "Error preparing delete: " . $conn->error; $message_type = "error"; error_log("Prep Del Subj Err: ".$conn->error); }
        }
    }
}

// ✅ Add Student & Assign Initial Subjects
if (isset($_POST['add_student_and_subjects'])) {
    $name = trim($_POST['name'] ?? ''); $college_uid = trim($_POST['college_uid'] ?? ''); $email = trim(strtolower($_POST['email'] ?? ''));
    $usn = isset($_POST['usn']) && trim($_POST['usn']) !== '' ? trim(strtoupper($_POST['usn'])) : null;
    $section = trim(strtoupper($_POST['section'] ?? '')); $department = trim($_POST['department'] ?? '');
    $semester = filter_var($_POST['semester'] ?? '', FILTER_VALIDATE_INT); $subjects_to_assign = $_POST['subjects_to_assign'] ?? [];
    $password = password_hash($default_password_plain, PASSWORD_DEFAULT); $role = 'student';

    if (!$can_manage_students) { $message = "Auth Error: No permission to add students."; $message_type = "error"; }
    elseif (empty($name)||empty($college_uid)||empty($email)||empty($section)||empty($department)) { $message = "Name, UID, Email, Section, Department required."; $message_type = "error"; }
    elseif (!$is_admin && $department !== $faculty_dept_for_auth) { $message = "Auth Error: Cannot add student to department '$department'."; $message_type = "error";}
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $message = "Invalid email format."; $message_type = "error"; }
    elseif ($semester === false || $semester < 1 || $semester > 8) { $message = "Valid Semester (1-8) required."; $message_type = "error"; }
    else {
        $checks_passed = true; $existing_error = '';
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE college_uid = ?"); if ($stmt_check) { $stmt_check->bind_param("s", $college_uid); $stmt_check->execute(); if ($stmt_check->get_result()->num_rows > 0) { $existing_error = "College UID '".htmlspecialchars($college_uid)."'"; } $stmt_check->close(); } else { $existing_error = "DB Error (UID Check)"; }
        if(empty($existing_error)) { $stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ?"); if ($stmt_check) { $stmt_check->bind_param("s", $email); $stmt_check->execute(); if ($stmt_check->get_result()->num_rows > 0) { $existing_error = "Email '".htmlspecialchars($email)."'"; } $stmt_check->close(); } else { $existing_error = "DB Error (Email Check)"; } }
        if(empty($existing_error) && !empty($usn)) { $stmt_check = $conn->prepare("SELECT id FROM students WHERE usn = ?"); if ($stmt_check) { $stmt_check->bind_param("s", $usn); $stmt_check->execute(); if ($stmt_check->get_result()->num_rows > 0) { $existing_error = "USN '".htmlspecialchars($usn)."'"; } $stmt_check->close(); } else { $existing_error = "DB Error (USN Check)"; } }

        if (!empty($existing_error)) { $message = "Action Failed: " . $existing_error . " already exists."; $message_type = "error"; $checks_passed = false; }

        if($checks_passed) {
            $conn->begin_transaction(); $new_student_record_id = null; $assigned_subject_count = 0;
            try {
                 $stmt_user = $conn->prepare("INSERT INTO users (name, email, college_uid, password, role, department, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?)"); if (!$stmt_user) throw new Exception("Err prep user"); $needs_change=true; $stmt_user->bind_param("ssssssi", $name, $email, $college_uid, $password, $role, $department, $needs_change); if (!$stmt_user->execute()) { if ($conn->errno == 1062) { throw new Exception("User insert failed: UID or Email exists."); } else { throw new Exception("Err exec user: " . $stmt_user->error); } } $new_user_id = $conn->insert_id; $stmt_user->close();
                 $stmt_student = $conn->prepare("INSERT INTO students (user_id, usn, section, department, semester) VALUES (?, ?, ?, ?, ?)"); if (!$stmt_student) throw new Exception("Err prep student"); $stmt_student->bind_param("isssi", $new_user_id, $usn, $section, $department, $semester); if (!$stmt_student->execute()) { if ($conn->errno == 1062 && !empty($usn)) { throw new Exception("Student insert failed: USN exists.");} else { throw new Exception("Err exec student: " . $stmt_student->error); } } $new_student_record_id = $conn->insert_id; $stmt_student->close();
                 if (!empty($subjects_to_assign) && $logged_in_faculty_id) {
                     $stmt_assign_mark = $conn->prepare("INSERT INTO marks (student_id, subject_id, faculty_id) VALUES (?, ?, ?)"); if (!$stmt_assign_mark) throw new Exception("Err prep marks");
                     foreach ($subjects_to_assign as $subject_id) {
                         if (!filter_var($subject_id, FILTER_VALIDATE_INT)) { continue; }
                         $stmt_assign_mark->bind_param("iii", $new_student_record_id, $subject_id, $logged_in_faculty_id); if (!$stmt_assign_mark->execute()) { throw new Exception("Err exec marks for Subj $subject_id: " . $stmt_assign_mark->error); } $assigned_subject_count++;
                     } $stmt_assign_mark->close();
                 }
                 elseif (!empty($subjects_to_assign) && !$logged_in_faculty_id && $is_admin) { error_log("Admin ID $logged_in_user_id added student $new_student_record_id but cannot be assigned as faculty."); }

                 $conn->commit(); $message = "Student '".htmlspecialchars($name)."' added."; if ($assigned_subject_count > 0) { $message .= " & $assigned_subject_count subject(s) assigned."; } $message .= " Default pass: '$default_password_plain'."; $message_type = "success";
            } catch (Exception $e) { $conn->rollback(); $message = "Error: " . $e->getMessage(); $message_type = "error"; error_log("Faculty Add Student Error: " . $e->getMessage()); }
        }
    }
}

// ✅ Edit Student
if (isset($_POST['edit_student'])) {
    $student_id = $_POST['student_id']; $name = trim($_POST['name']);
    $usn = isset($_POST['usn']) && trim($_POST['usn']) !== '' ? trim(strtoupper($_POST['usn'])) : null;
    $section = trim(strtoupper($_POST['section'])); $requesting_user_dept = $user_info['department'] ?? null;

    if (empty($name) || empty($section) || empty($student_id)) { $message = "Name, Section, ID required."; $message_type = "error"; }
    elseif (!$can_manage_students) { $message = "Auth Error: No permission."; $message_type = "error"; }
    else {
        $conn->begin_transaction();
        try {
            $stmt_get = $conn->prepare("SELECT s.user_id, s.department, s.usn as current_usn, u.email as current_email FROM students s JOIN users u ON s.user_id = u.id WHERE s.id = ?");
            if (!$stmt_get) throw new Exception("Err prepare get student"); $stmt_get->bind_param("i", $student_id); $stmt_get->execute(); $result = $stmt_get->get_result(); if ($result->num_rows === 0) throw new Exception("Student not found.");
            $student_data = $result->fetch_assoc(); $user_id = $student_data['user_id']; $student_dept = $student_data['department']; $current_usn = $student_data['current_usn']; $current_email = $student_data['current_email']; $stmt_get->close();

            if (!$is_admin && $student_dept !== $requesting_user_dept) { throw new Exception("Auth Error: Cannot edit student in department '$student_dept'."); }

            if (!empty($usn) && $usn !== $current_usn) {
                $stmt_check = $conn->prepare("SELECT id FROM students WHERE usn = ? AND id != ?"); if(!$stmt_check) throw new Exception("Err prepare check USN"); $stmt_check->bind_param("si", $usn, $student_id); $stmt_check->execute(); if($stmt_check->get_result()->num_rows > 0) { throw new Exception("USN '".htmlspecialchars($usn)."' already exists."); } $stmt_check->close();
            }

            $stmt_upd_stud = $conn->prepare("UPDATE students SET usn = ?, section = ? WHERE id = ?"); if (!$stmt_upd_stud) throw new Exception("Err prepare update student"); $stmt_upd_stud->bind_param("ssi", $usn, $section, $student_id); if (!$stmt_upd_stud->execute()) throw new Exception("Err update student: " . $stmt_upd_stud->error); $stmt_upd_stud->close();

            $email_to_update = null;
            if (!empty($usn) && $usn !== $current_usn) {
                 $new_email = strtolower($usn) . "@university.edu"; // Your chosen format
                 if ($new_email !== $current_email) {
                     $stmt_check_email = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?"); if(!$stmt_check_email) throw new Exception("Err prepare check email"); $stmt_check_email->bind_param("si", $new_email, $user_id); $stmt_check_email->execute(); if ($stmt_check_email->get_result()->num_rows > 0) { throw new Exception("Cannot update USN: generated email ('".htmlspecialchars($new_email)."') already exists."); } $stmt_check_email->close();
                     $email_to_update = $new_email;
                 }
            }

            if ($email_to_update !== null) {
                 $stmt_upd_user = $conn->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?"); if (!$stmt_upd_user) throw new Exception("Err prepare user name/email update"); $stmt_upd_user->bind_param("ssi", $name, $email_to_update, $user_id);
            } else {
                 $stmt_upd_user = $conn->prepare("UPDATE users SET name = ? WHERE id = ?"); if (!$stmt_upd_user) throw new Exception("Err prepare user name update"); $stmt_upd_user->bind_param("si", $name, $user_id);
            }
            if (!$stmt_upd_user->execute()) throw new Exception("Err update user: " . $stmt_upd_user->error); $stmt_upd_user->close();

            $conn->commit(); $message = "Student updated successfully!"; if ($email_to_update !== null) { $message .= " Email updated."; } $message_type = "success";
        } catch (Exception $e) { $conn->rollback(); $message = "Error updating student: " . $e->getMessage(); $message_type = "error"; error_log("Edit Student Error: " . $e->getMessage()); }
    }
}

// ✅ Delete Student
if (isset($_POST['delete_student'])) {
    $student_id = $_POST['student_id']; $requesting_user_dept = $user_info['department'] ?? null;
    if (empty($student_id)) { $message = "Student ID missing."; $message_type = "error"; }
    elseif (!$can_manage_students) { $message = "Auth Error: No permission."; $message_type = "error"; }
    else {
        $conn->begin_transaction();
        try {
            $stmt_get = $conn->prepare("SELECT user_id, department FROM students WHERE id = ?"); if (!$stmt_get) throw new Exception("Err prepare get info"); $stmt_get->bind_param("i", $student_id); $stmt_get->execute(); $result = $stmt_get->get_result(); if ($result->num_rows === 0) throw new Exception("Student not found."); $student_data = $result->fetch_assoc(); $user_id = $student_data['user_id']; $student_dept = $student_data['department']; $stmt_get->close();

            if (!$is_admin && $student_dept !== $requesting_user_dept) { throw new Exception("Auth Error: Cannot delete student in department '$student_dept'."); }

            $stmt_del_marks = $conn->prepare("DELETE FROM marks WHERE student_id = ?"); if (!$stmt_del_marks) throw new Exception("Err prepare del marks"); $stmt_del_marks->bind_param("i", $student_id); $stmt_del_marks->execute(); $stmt_del_marks->close();
            $stmt_del_stud = $conn->prepare("DELETE FROM students WHERE id = ?"); if (!$stmt_del_stud) throw new Exception("Err prepare del student"); $stmt_del_stud->bind_param("i", $student_id); if (!$stmt_del_stud->execute()) throw new Exception("Err delete student"); $rows_affected = $stmt_del_stud->affected_rows; $stmt_del_stud->close();

            if ($rows_affected > 0 && $user_id) { $stmt_del_user = $conn->prepare("DELETE FROM users WHERE id = ?"); if (!$stmt_del_user) throw new Exception("Err prepare del user"); $stmt_del_user->bind_param("i", $user_id); if (!$stmt_del_user->execute()) throw new Exception("Err delete user"); $stmt_del_user->close(); }
            elseif ($rows_affected == 0) { throw new Exception("Student record not found for deletion."); }

            $conn->commit(); $message = "Student deleted successfully!"; $message_type = "success";
        } catch (Exception $e) { $conn->rollback(); $message = "Error deleting student: " . $e->getMessage(); $message_type = "error"; error_log("Delete Student Error: " . $e->getMessage()); }
    }
}

// ⭐✅ Assign Subject to Student ⭐
if (isset($_POST['assign_subject'])) {
    $assign_student_id = filter_input(INPUT_POST, 'assign_student_id', FILTER_VALIDATE_INT);
    $assign_subject_id = filter_input(INPUT_POST, 'assign_subject_id', FILTER_VALIDATE_INT);
    $assigning_faculty_id = $logged_in_faculty_id; // NULL if admin

    if (!$can_assign_subjects) { $message = "Auth Error: No permission."; $message_type = "error"; }
    elseif (empty($assign_student_id) || empty($assign_subject_id)) { $message = "Student and Subject required."; $message_type = "error"; }
    else {
        $student_dept_for_assign = null;
        if (!$is_admin && $logged_in_faculty_id) {
             $stmt_get_dept = $conn->prepare("SELECT department FROM students WHERE id = ?"); if ($stmt_get_dept) { $stmt_get_dept->bind_param("i", $assign_student_id); $stmt_get_dept->execute(); $res_dept = $stmt_get_dept->get_result(); if ($row_dept = $res_dept->fetch_assoc()) { $student_dept_for_assign = $row_dept['department']; } $stmt_get_dept->close(); }
        }

        if (!$is_admin && $student_dept_for_assign !== $faculty_dept_for_auth) { $message = "Auth Error: Cannot assign subject to student in dept '$student_dept_for_assign'."; $message_type = "error"; }
        else {
             $check_assign = $conn->prepare("SELECT id FROM marks WHERE student_id = ? AND subject_id = ?");
             if ($check_assign) {
                 $check_assign->bind_param("ii", $assign_student_id, $assign_subject_id); $check_assign->execute(); $result_assign = $check_assign->get_result();
                 if ($result_assign->num_rows > 0) { $message = "Subject already assigned."; $message_type = "info"; }
                 else {
                     $stmt_assign = $conn->prepare("INSERT INTO marks (student_id, subject_id, faculty_id) VALUES (?, ?, ?)");
                      if($stmt_assign) {
                          $stmt_assign->bind_param("iii", $assign_student_id, $assign_subject_id, $assigning_faculty_id);
                          if ($stmt_assign->execute()) { $message = "Subject assigned."; if ($assigning_faculty_id) { $message .= " Ready for marks entry."; } else { $message .= " Faculty can now enter marks."; } $message_type = "success"; }
                          else { $message = "Error assign: " . $stmt_assign->error; if ($stmt_assign->errno == 1452) { $message = "Error: Student or Subject not found."; } $message_type = "error"; error_log("Assign Subj Err: ".$stmt_assign->error); }
                          $stmt_assign->close();
                      } else { $message = "Err prep assign: " . $conn->error; $message_type = "error"; error_log("Prep Assign Subj Err: ".$conn->error); }
                 } $check_assign->close();
             } else { $message = "Err check assign: " . $conn->error; $message_type = "error"; error_log("Prep Check Assign Err: ".$conn->error); }
        }
    }
}

// --- MARKS MANAGEMENT (Faculty Only) ---
if ($logged_in_role == 'faculty' && $logged_in_faculty_id) { // Ensure faculty and ID exists

    // ✅ Enter Marks
    if (isset($_POST['enter_marks'])) {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT); $marks_entered_updated = false; $warning_messages = []; $calculation_errors = [];

        if (empty($student_id)) { $message = "Select student."; $message_type = "error"; }
        elseif (!isset($_POST['subject_marks']) || !is_array($_POST['subject_marks'])) { $message = "No marks data received."; $message_type = "info"; }
        else {
            $conn->begin_transaction();
            try {
                foreach ($_POST['subject_marks'] as $subject_id => $fields) {
                     if (!filter_var($subject_id, FILTER_VALIDATE_INT)) { continue; }
                     $stmt_check = $conn->prepare("SELECT id FROM marks WHERE student_id = ? AND subject_id = ? AND faculty_id = ?"); if(!$stmt_check) throw new Exception("Err prep check mark"); $stmt_check->bind_param("iii", $student_id, $subject_id, $logged_in_faculty_id); $stmt_check->execute(); $result = $stmt_check->get_result(); $existing_mark_id = ($result->num_rows > 0) ? $result->fetch_assoc()['id'] : null; $stmt_check->close();
                     if (!$existing_mark_id) { $warning_messages[] = "Skip Subj $subject_id (not yours)."; continue; }
                     $type = $fields['type'] ?? null; if(!$type) { $calculation_errors[] = "No type Subj $subject_id."; continue; }
                     $get_num = function($k) use ($fields) { return (isset($fields[$k]) && $fields[$k] !== '' && is_numeric($fields[$k])) ? (float)$fields[$k] : null; };
                     $ia1=$get_num('ia1'); $ia2=$get_num('ia2'); $assignment=$get_num('assignment'); $record1=$get_num('record1'); $lab_ia1=$get_num('lab_ia1'); $record2=$get_num('record2'); $lab_ia2=$get_num('lab_ia2'); $total=null;
                     if ($type=='Theory') { $avg_ia = ($ia1 !== null && $ia2 !== null) ? round(($ia1+$ia2)/2.0,2) : ($ia1 ?? $ia2 ?? null); $total = ($avg_ia!==null || $assignment!==null) ? round(max(0,($avg_ia??0.0)) + max(0,($assignment??0.0))) : null; }
                     elseif ($type=='IPCC') { $avg_ia = ($ia1 !== null && $ia2 !== null) ? round(($ia1+$ia2)/2.0,2) : ($ia1 ?? $ia2 ?? null); $scaled_ia = ($avg_ia!==null) ? round(max(0,$avg_ia)*0.6,2) : null; $total = ($scaled_ia!==null || $assignment!==null || $record1!==null || $lab_ia1!==null) ? round(max(0,($scaled_ia??0.0)) + max(0,($assignment??0.0)) + max(0,($record1??0.0)) + max(0,($lab_ia1??0.0))) : null; }
                     elseif ($type=='Lab') { $total = ($record1!==null || $lab_ia1!==null || $record2!==null || $lab_ia2!==null) ? round(max(0,($record1??0.0)) + max(0,($lab_ia1??0.0)) + max(0,($record2??0.0)) + max(0,($lab_ia2??0.0))) : null; }
                     else { $calculation_errors[] = "Unk type $type Subj $subject_id."; }
                     $stmt_upd = $conn->prepare("UPDATE marks SET ia1=?, ia2=?, assignment=?, lab_record1=?, lab_ia1=?, lab_record2=?, lab_ia2=?, total_marks=? WHERE id=?"); if(!$stmt_upd) throw new Exception("Err prep update marks"); $stmt_upd->bind_param("ddddddddi", $ia1, $ia2, $assignment, $record1, $lab_ia1, $record2, $lab_ia2, $total, $existing_mark_id); if (!$stmt_upd->execute()) { throw new Exception("Err exec update Subj $subject_id: ".$stmt_upd->error); } if ($stmt_upd->affected_rows > 0 || $existing_mark_id) { $marks_entered_updated = true; } $stmt_upd->close();
                }
                if (!empty($calculation_errors)) { $warning_messages[] = "Calc Warn: " . implode("; ", $calculation_errors); }
                $conn->commit();
                if ($marks_entered_updated) {
                    $message = "Marks processed!"; $message_type = "success"; if (!empty($warning_messages)) { $message .= " Warn: " . implode(" ", $warning_messages); $message_type = "warning"; }
                    $query_disp = $conn->prepare("SELECT sub.name AS sn, sub.code sc, sub.type st, m.ia1, m.ia2, m.assignment, m.lab_record1, m.lab_ia1, m.lab_record2, m.lab_ia2, m.total_marks FROM marks m JOIN subjects sub ON m.subject_id=sub.id WHERE m.student_id=? AND m.faculty_id=? ORDER BY st, sn"); if($query_disp) { $query_disp->bind_param("ii", $student_id, $logged_in_faculty_id); $query_disp->execute(); $res_disp = $query_disp->get_result(); $submitted_marks_html .= "<h4 class='mt-4'><i class='fas fa-check-double'></i> Submitted Marks</h4><div class='table-responsive'><table class='data-table'><thead><tr><th>Sub</th><th>Code</th><th>Type</th><th>IA1</th><th>IA2</th><th>Assign</th><th>Rec1</th><th>LIA1</th><th>Rec2</th><th>LIA2</th><th>Total</th></tr></thead><tbody>"; if ($res_disp->num_rows>0) { while ($rd=$res_disp->fetch_assoc()) { $d = function($v) { return ($v===null||$v==='')?'-':htmlspecialchars($v); }; $submitted_marks_html.="<tr><td>".htmlspecialchars($rd['sn'])."</td><td>".htmlspecialchars($rd['sc'])."</td><td>".htmlspecialchars($rd['st'])."</td><td>".$d($rd['ia1'])."</td><td>".$d($rd['ia2'])."</td><td>".$d($rd['assignment'])."</td><td>".$d($rd['lab_record1'])."</td><td>".$d($rd['lab_ia1'])."</td><td>".$d($rd['lab_record2'])."</td><td>".$d($rd['lab_ia2'])."</td><td><strong>".$d($rd['total_marks'])."</strong></td></tr>";}} else {$submitted_marks_html.="<tr><td colspan='11' class='text-center'>No marks assigned to you.</td></tr>";} $submitted_marks_html.="</tbody></table></div>"; $query_disp->close(); } else { $submitted_marks_html = "<p class='text-danger'>Err fetch confirm</p>"; }
                } else { $message = "No valid marks processed."; if (!empty($warning_messages)) { $message .= " Issues: " . implode(" ", $warning_messages); $message_type = "warning"; } else { $message_type = "info"; }}
            } catch (Exception $e) { $conn->rollback(); $message = "Error: " . $e->getMessage(); $message_type = "error"; error_log("Marks Entry Error: " . $e->getMessage()); }
        }
    }

    // ✅ Update/Delete Marks from Edit Marks Tab
    if (isset($_POST['update_all_marks']) && isset($_POST['student_id']) && isset($_POST['edit_marks'])) {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT); if ($student_id) { $conn->begin_transaction(); $update_errors = []; $updates_made = 0; try { foreach ($_POST['edit_marks'] as $subject_id => $marks) { if (!filter_var($subject_id, FILTER_VALIDATE_INT)) continue; $subject_type=null; $stmt_type = $conn->prepare("SELECT type FROM subjects WHERE id = ?"); if($stmt_type){$stmt_type->bind_param("i",$subject_id);$stmt_type->execute();$type_res=$stmt_type->get_result();if($t_row=$type_res->fetch_assoc()){$subject_type=$t_row['type'];}$stmt_type->close();}else{$update_errors[]="Err type $subject_id"; continue;} if(!$subject_type){continue;} $get_num_edit = function($k) use ($marks) { return (isset($marks[$k]) && $marks[$k] !== '' && is_numeric($marks[$k])) ? (float)$marks[$k] : null; }; $ia1=$get_num_edit('ia1'); $ia2=$get_num_edit('ia2'); $assignment=$get_num_edit('assignment'); $record1=$get_num_edit('record1'); $lab_ia1=$get_num_edit('lab_ia1'); $record2=$get_num_edit('record2'); $lab_ia2=$get_num_edit('lab_ia2'); $total=null; if ($subject_type=='Theory') { $avg_ia = ($ia1 !== null && $ia2 !== null) ? round(($ia1+$ia2)/2.0,2) : ($ia1 ?? $ia2 ?? null); $total = ($avg_ia!==null || $assignment!==null) ? round(max(0,($avg_ia??0.0)) + max(0,($assignment??0.0))) : null; } elseif ($subject_type=='IPCC') { $avg_ia = ($ia1 !== null && $ia2 !== null) ? round(($ia1+$ia2)/2.0,2) : ($ia1 ?? $ia2 ?? null); $scaled_ia = ($avg_ia!==null) ? round(max(0,$avg_ia)*0.6,2) : null; $total = ($scaled_ia!==null || $assignment!==null || $record1!==null || $lab_ia1!==null) ? round(max(0,($scaled_ia??0.0)) + max(0,($assignment??0.0)) + max(0,($record1??0.0)) + max(0,($lab_ia1??0.0))) : null; } elseif ($subject_type=='Lab') { $total = ($record1!==null || $lab_ia1!==null || $record2!==null || $lab_ia2!==null) ? round(max(0,($record1??0.0)) + max(0,($lab_ia1??0.0)) + max(0,($record2??0.0)) + max(0,($lab_ia2??0.0))) : null; } $stmt = $conn->prepare("UPDATE marks SET ia1=?, ia2=?, assignment=?, lab_record1=?, lab_ia1=?, lab_record2=?, lab_ia2=?, total_marks=? WHERE student_id=? AND subject_id=? AND faculty_id = ?"); if(!$stmt) { $update_errors[]="Err prep update $subject_id"; continue; } $stmt->bind_param("ddddddddiii", $ia1, $ia2, $assignment, $record1, $lab_ia1, $record2, $lab_ia2, $total, $student_id, $subject_id, $logged_in_faculty_id); if (!$stmt->execute()) { $update_errors[]="Err exec update $subject_id: ".$stmt->error; } else { if ($stmt->affected_rows>0) { $updates_made++; } } $stmt->close(); } if (!empty($update_errors)) { throw new Exception(implode("; ", $update_errors)); } $conn->commit(); if ($updates_made>0) { $message="Marks updated!"; $message_type="success"; } else { $message="No marks changed."; $message_type="info"; } } catch (Exception $e) { $conn->rollback(); $message="Error updating marks: ".$e->getMessage(); $message_type="error"; error_log("Update All Error: ".$e->getMessage()); } $_POST['load_marks']=1; $_POST['student_id_select']=$student_id; } else {$message="Invalid student ID.";$message_type="error";}
    }

    // Handle Delete Button from Edit Marks Tab
    if (isset($_POST['delete_marks_entry']) && isset($_POST['student_id'])) {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT); $subject_id = filter_var($_POST['delete_marks_entry'], FILTER_VALIDATE_INT);
         if (empty($student_id) || empty($subject_id)) { $message="Invalid data for delete."; $message_type="error"; }
         else { $stmt = $conn->prepare("DELETE FROM marks WHERE student_id = ? AND subject_id = ? AND faculty_id = ?"); if($stmt) { $stmt->bind_param("iii", $student_id, $subject_id, $logged_in_faculty_id); if ($stmt->execute()) { if ($stmt->affected_rows > 0) { $message="Marks entry deleted!"; $message_type="success"; } else { $message="No entry found to delete."; $message_type="info"; } } else { $message="Error deleting: ".$stmt->error; $message_type="error"; } $stmt->close(); } else { $message="Err prepare delete."; $message_type="error"; } }
         if($student_id) { $_POST['load_marks']=1; $_POST['student_id_select']=$student_id; }
    }

} // End if($logged_in_role == 'faculty') for marks management sections
// --- End of PHP Logic ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(ucfirst($logged_in_role)) ?> Dashboard - University Portal</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
         :root { --primary-color: #4a00e0; --secondary-color: #8e2de2; --accent-color: #00bcd4; --bg-color: #f4f7fc; --card-bg: #ffffff; --text-color: #333; --text-muted: #6c757d; --border-color: #e0e0e0; --success-color: #198754; --error-color: #dc3545; --info-color: #0dcaf0; --warning-color: #ffc107; --success-bg: #d1e7dd; --error-bg: #f8d7da; --info-bg: #cff4fc; --warning-bg: #fff3cd; --border-radius: 8px; --box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); --input-bg: #fff; --input-focus-border: var(--secondary-color); --input-focus-shadow: rgba(142, 45, 226, 0.2); }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; padding: 0; line-height: 1.6; }
        .dashboard-container { max-width: 1400px; margin: 30px auto; padding: 25px; background-color: var(--card-bg); border-radius: var(--border-radius); box-shadow: var(--box-shadow); }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color); flex-wrap: wrap; }
        .dashboard-header h2 { margin: 0 0 10px 0; color: var(--primary-color); font-weight: 600; flex-basis: 100%; text-align: center; }
        .dashboard-header h2 i { margin-right: 10px; }
        .dashboard-header a, .logout-btn { text-decoration: none; padding: 8px 15px; background-color: var(--error-color); color: white; border-radius: 5px; font-size: 0.9rem; transition: background-color 0.3s ease; margin-top: 5px; }
        .dashboard-header a:hover, .logout-btn:hover { background-color: #c82333; }
        @media (min-width: 576px) { .dashboard-header h2 { flex-basis: auto; text-align: left; margin-bottom: 0; } }
        .tabs { display: flex; border-bottom: 2px solid var(--border-color); margin-bottom: 25px; overflow-x: auto; scrollbar-width: thin; scrollbar-color: var(--secondary-color) var(--bg-color); }
        .tabs::-webkit-scrollbar { height: 6px; } .tabs::-webkit-scrollbar-track { background: var(--bg-color); border-radius: 3px; } .tabs::-webkit-scrollbar-thumb { background-color: var(--secondary-color); border-radius: 3px; }
        .tab-link { padding: 12px 20px; cursor: pointer; border: none; background-color: transparent; font-size: 1rem; font-weight: 500; color: var(--text-muted); transition: all 0.3s ease; border-bottom: 3px solid transparent; margin-bottom: -2px; white-space: nowrap; flex-shrink: 0; }
        .tab-link i { margin-right: 8px; } .tab-link:hover { color: var(--primary-color); } .tab-link.active { color: var(--primary-color); font-weight: 600; border-bottom-color: var(--primary-color); }
        .tab-content { display: none; padding: 15px 5px; animation: fadeIn 0.5s ease; } @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } } .tab-content.active { display: block; }
        h3, h4 { color: var(--secondary-color); margin-top: 25px; margin-bottom: 20px; font-weight: 600; padding-bottom: 5px; } h3 i, h4 i { margin-right: 10px; color: var(--primary-color); }
        .form-section { background: #fdfdff; padding: 25px 30px; border-radius: var(--border-radius); box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); margin-bottom: 30px; border: 1px solid #ececec; }
        .form-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 15px; } .form-col { flex: 1; min-width: 180px; } .form-col-auto { flex: 0 0 auto; align-self: flex-end; padding-bottom: 15px; }
        .form-section legend { font-weight: 600; margin-bottom: 15px; color: var(--primary-color); font-size: 1.1rem; padding-bottom: 8px; border-bottom: 1px dashed var(--border-color); }
        label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 0.9rem; color: #555; }
        input[type="text"], input[type="email"], input[type="password"], input[type="number"], select, .select2-container .select2-selection--single { width: 100%; padding: 10px 12px; margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 5px; font-size: 0.95rem; box-sizing: border-box; transition: border-color 0.3s ease, box-shadow 0.3s ease; height: auto !important; background-color: var(--input-bg); }
        .select2-container .select2-selection--single { height: calc(1.5em + .75rem + 7px) !important; display: flex; align-items: center; }
        .select2-container--default .select2-selection--multiple { border: 1px solid var(--border-color); border-radius: 5px; padding: 5px 8px; min-height: calc(1.5em + .75rem + 7px); cursor: text; background-color: var(--input-bg); margin-bottom: 15px; }
        .select2-container--default .select2-selection--multiple .select2-selection__rendered { padding: 0; }
        .select2-container--default .select2-selection--multiple .select2-selection__choice { background-color: #e9ecef; border: 1px solid #ced4da; border-radius: 4px; padding: 2px 6px; margin: 2px; }
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove { margin-left: 5px; cursor: pointer; }
        .select2-container--default.select2-container--focus .select2-selection--multiple { border-color: var(--input-focus-border); box-shadow: 0 0 0 0.2rem var(--input-focus-shadow); }
        input:focus, select:focus, .select2-container--focus .select2-selection--single, .select2-container--focus .select2-selection--multiple { border-color: var(--input-focus-border); box-shadow: 0 0 0 0.2rem var(--input-focus-shadow); outline: none; }
        input:disabled, select:disabled, .select2-container--disabled .select2-selection--single, .select2-container--disabled .select2-selection--multiple { background-color: #e9ecef; cursor: not-allowed; opacity: 0.7; }
        input[readonly] { background-color: #e9ecef; cursor: default; opacity: 0.8; }
        .btn { padding: 10px 18px; border: none; border-radius: 5px; cursor: pointer; font-size: 0.95rem; font-weight: 500; transition: all 0.3s ease; margin-right: 5px; margin-top: 5px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn:disabled { cursor: not-allowed; opacity: 0.65; background-image: none; background-color: #adb5bd; border-color: #adb5bd; box-shadow: none; }
        .btn-sm { padding: 6px 12px; font-size: 0.85rem; gap: 5px; } .btn-lg { padding: 12px 24px; font-size: 1.1rem; gap: 10px; } .w-100 { width: 100%; }
        .btn-light { background-color: #f8f9fa; color: #333; border: 1px solid #dee2e6; } .btn-light:hover:not(:disabled) { background-color: #e2e6ea; border-color: #dae0e5; }
        .btn-primary { background: linear-gradient(to right, var(--primary-color), var(--secondary-color)); color: white; border: 1px solid var(--primary-color); } .btn-primary:hover:not(:disabled) { background: linear-gradient(to right, var(--secondary-color), var(--primary-color)); transform: translateY(-1px); box-shadow: 0 4px 10px rgba(74, 0, 224, 0.2); }
        .btn-secondary { background-color: var(--accent-color); color: white; border: 1px solid var(--accent-color); } .btn-secondary:hover:not(:disabled) { background-color: #00a5bb; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0, 188, 212, 0.2); border-color: #008a9e; }
        .btn-danger { background-color: var(--error-color); color: white; border: 1px solid var(--error-color); } .btn-danger:hover:not(:disabled) { background-color: #c82333; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(220, 53, 69, 0.2); border-color: #b02a37; }
        .table-responsive { overflow-x: auto; margin-top: 20px; border: 1px solid var(--border-color); border-radius: var(--border-radius); box-shadow: var(--box-shadow); }
        .data-table { width: 100%; border-collapse: collapse; background-color: var(--card-bg); }
        .data-table th, .data-table td { padding: 12px 15px; border-bottom: 1px solid var(--border-color); border-left: none; border-right: none; text-align: left; font-size: 0.9rem; vertical-align: middle; white-space: nowrap; }
        .data-table th:first-child, .data-table td:first-child { padding-left: 20px; width: 60px; text-align: center; }
        .data-table th:nth-child(2), .data-table td:nth-child(2) { padding-left: 5px; }
        .data-table th:last-child, .data-table td:last-child { padding-right: 20px;}
        .data-table thead th { background-color: #f8f9fa; color: var(--primary-color); font-weight: 600; text-align: left; border-bottom-width: 2px; border-top: none !important; }
        .data-table thead th small { font-weight: 400; font-size: 0.8em; color: var(--text-muted); display: block; }
        .data-table thead th:first-child { text-align: center; }
        .data-table tbody tr { transition: background-color 0.2s ease; } .data-table tbody tr:hover { background-color: #eef2f7; }
        .data-table td input[type="text"], .data-table td input[type="number"], .data-table td select { padding: 6px 8px; font-size: 0.85rem; margin-bottom: 0; border-radius: 4px; display: block; width: 100%; box-sizing: border-box; }
        .data-table td input:disabled, .data-table td select:disabled { background-color: #f8f9fa; color: #6c757d; border-color: #dee2e6; cursor: default; opacity: 0.7;}
        .data-table td input[name*="marks"], .data-table td input[name*="edit_marks"] { max-width: 90px; width:auto; display: inline-block; }
        #manageStudents .data-table td input[name="section"] { max-width: 80px; }
        #manageSubjects .data-table td select[name='subject_type'] { max-width: 120px; }
        .data-table .action-buttons { white-space: nowrap; padding-left: 15px; }
        .data-table .action-buttons form { display: inline-block; margin: 0 3px 0 0; background: none; padding: 0; border-radius: 0; box-shadow: none; margin-bottom: 0; border: none; }
        .data-table .action-buttons .btn { margin: 0 0 5px 0; display: inline-flex; width: auto; }
        .display-value { display: inline; }
        .edit-input { display: none; margin-bottom: 0 !important; }
        .message-area { padding: 15px 20px; margin: 20px 0; border-radius: var(--border-radius); font-weight: 500; display: none; border: 1px solid transparent; animation: fadeIn 0.5s ease; }
        .message-area i { margin-right: 8px; }
        .message-area.success { background-color: var(--success-bg); color: var(--success-color); border-color: var(--success-color); display: block; }
        .message-area.error { background-color: var(--error-bg); color: var(--error-color); border-color: var(--error-color); display: block; }
        .message-area.info { background-color: var(--info-bg); color: var(--info-color); border-color: var(--info-color); display: block; }
        .message-area.warning { background-color: var(--warning-bg); color: var(--warning-color); border-color: var(--warning-color); display: block; }
        fieldset { border: 1px solid #e0e5ec; padding: 20px; margin-bottom: 20px; border-radius: var(--border-radius); background-color: #fdfdff; }
        fieldset legend { font-weight: 600; padding: 0 10px; color: var(--secondary-color); font-size: 1rem; width: auto; border-bottom: none; margin-bottom: 10px; }
        fieldset .marks-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px 20px; }
        fieldset .mark-item label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 0.85rem; }
        fieldset .mark-item input[type="number"] { width: 100%; display: block; margin-right: 0; margin-bottom: 0; }
        .section-filters, .department-filters { margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px dashed var(--border-color); display: flex; flex-wrap: wrap; gap: 10px; }
        .section-filters .btn, .department-filters .btn { background-color: #e9ecef; color: var(--text-color); border: 1px solid #ced4da; }
        .section-filters .btn.active, .department-filters .btn.active { background-color: var(--secondary-color); color: white; border-color: var(--secondary-color); }
        @media (max-width: 768px) { .dashboard-container { margin: 15px; padding: 15px; } .tabs { font-size: 0.9rem; } .tab-link { padding: 10px 15px; } .form-section { padding: 20px; } .form-row { flex-direction: column; gap: 0; } .form-col-auto { width: 100%; align-self: stretch; padding-bottom: 0;} .data-table td input[type="text"], .data-table td input[type="number"], .data-table td select { width: 100%; max-width: none; } .data-table td input[name*="marks"] { width: 100%; max-width: none; } fieldset .marks-grid { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); } .btn { width: 100%; margin-bottom: 10px; } .data-table .action-buttons .btn { width: auto; margin-bottom: 5px;} .section-filters .btn, .department-filters .btn { width: auto; } .data-table th:first-child, .data-table td:first-child { width: 45px; padding-left: 10px; padding-right: 10px; } }
        .select2-container { width: 100% !important; margin-bottom: 15px; }
        .select2-container--default .select2-selection--single { border: 1px solid var(--border-color); border-radius: 5px; height: calc(1.5em + .75rem + 7px) !important; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: calc(1.5em + .75rem + 5px); padding-left: 12px; color: var(--text-color); }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: calc(1.5em + .75rem + 5px); right: 8px; }
        .select2-container--default .select2-selection--single .select2-selection__placeholder { color: #6c757d; }
        .select2-container--default.select2-container--open .select2-selection--single { border-color: var(--input-focus-border); box-shadow: 0 0 0 0.2rem var(--input-focus-shadow); }
        .select2-dropdown { border: 1px solid var(--input-focus-border); box-shadow: 0 6px 12px rgba(0,0,0,0.1); border-radius: 5px; margin-top: 2px; z-index: 1051; }
        .select2-search--dropdown .select2-search__field { border: 1px solid var(--border-color); border-radius: 4px; padding: 8px 10px; }
        .select2-results__option { padding: 8px 12px; } .select2-results__option--highlighted[aria-selected] { background-color: var(--primary-color); color: white; }
        .form-text { margin-top: .25rem; font-size: .875em; color: #6c757d; }
        .text-center { text-align: center !important; }
    </style>
</head>
<body>

<div class="dashboard-container">

    <div class="dashboard-header">
        <h2><i class="fas <?= $is_admin ? 'fa-user-shield' : 'fa-chalkboard-teacher' ?>"></i> <?= htmlspecialchars(ucfirst($logged_in_role)) ?> Dashboard</h2>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <?php if (!empty($message)): ?>
        <div class="message-area <?= htmlspecialchars($message_type) ?>">
            <?php if ($message_type == 'success'): ?><i class="fas fa-check-circle"></i><?php endif; ?>
            <?php if ($message_type == 'error'): ?><i class="fas fa-times-circle"></i><?php endif; ?>
            <?php if ($message_type == 'info'): ?><i class="fas fa-info-circle"></i><?php endif; ?>
            <?php if ($message_type == 'warning'): ?><i class="fas fa-exclamation-triangle"></i><?php endif; ?>
            <?= $message ?>
        </div>
    <?php endif; ?>

    <div class="tabs">
        <button class="tab-link active" onclick="openTab(event, 'dashboardInfo')" id="defaultOpen"><i class="fas fa-tachometer-alt"></i> Dashboard</button>
        <button class="tab-link" onclick="openTab(event, 'manageSubjects')"><i class="fas fa-book"></i> Manage Subjects</button>
        <?php if ($can_manage_students || $can_assign_subjects): ?>
            <button class="tab-link" onclick="openTab(event, 'manageStudents')"><i class="fas fa-user-graduate"></i> Manage Students</button>
        <?php endif; ?>
        <?php if ($logged_in_role == 'faculty' && $logged_in_faculty_id): ?>
            <button class="tab-link" onclick="openTab(event, 'enterMarks')"><i class="fas fa-keyboard"></i> Enter Marks</button>
            <button class="tab-link" onclick="openTab(event, 'editMarks')"><i class="fas fa-tasks"></i> View/Edit Marks</button>
        <?php endif; ?>
    </div>

    <!-- Tab Content -->
    <div id="dashboardInfo" class="tab-content active">
         <h3><i class="fas fa-info-circle"></i> Welcome, <?= htmlspecialchars($user_info['name'] ?? 'User') ?>!</h3>
         <?php if ($user_info): ?>
            <div class="form-section">
                 <p><strong>Role:</strong> <?= htmlspecialchars(ucfirst($user_info['role'])) ?></p>
                 <p><strong>Name:</strong> <?= htmlspecialchars($user_info['name']) ?></p>
                 <p><strong>College UID:</strong> <?= htmlspecialchars($user_info['college_uid'] ?? 'N/A') ?></p>
                 <p><strong>Email:</strong> <?= htmlspecialchars($user_info['email']) ?></p>
                 <p><strong>Department:</strong> <?= htmlspecialchars($user_info['department'] ?? 'N/A') ?></p>
                 <?php if(empty($user_info['department']) && $user_info['role'] == 'faculty'): ?>
                    <p class="message-area warning"><i class="fas fa-exclamation-triangle"></i> Department not assigned. Contact admin for full access.</p>
                 <?php endif; ?>
             </div>
         <?php else: ?>
             <p class="message-area error"><i class="fas fa-times-circle"></i> Could not retrieve user info.</p>
         <?php endif; ?>
         <p>Use tabs to navigate.</p>
    </div>

    <div id="manageSubjects" class="tab-content">
        <h3><i class="fas fa-book-open"></i> Manage Subjects</h3>
        <div class="form-section">
             <h4><i class="fas fa-plus-circle"></i> Add New Subject</h4>
             <form method="post"> <input type="hidden" name="add_subject" value="1"> <div class="form-row"> <div class="form-col"> <label for="add_subject_name" class="form-label">Name</label> <input type="text" id="add_subject_name" name="subject_name" required class="form-control" placeholder="e.g., Data Structures"> </div> <div class="form-col"> <label for="add_subject_code" class="form-label">Code</label> <input type="text" id="add_subject_code" name="subject_code" required class="form-control" placeholder="e.g., CS301"> </div> <div class="form-col"> <label for="add_subject_type" class="form-label">Type</label> <select id="add_subject_type" name="subject_type" required class="form-select select2" data-placeholder="-- Select Type --"> <option value=""></option> <option>Theory</option> <option>IPCC</option> <option>Lab</option> </select> </div> <div class="form-col-auto"> <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add</button> </div> </div> <?php if($is_admin): ?><small class="form-text text-muted">Added by Admin.</small><?php else: ?><small class="form-text text-muted">Linked to <?= htmlspecialchars($user_info['name'] ?? '') ?>.</small><?php endif; ?></form>
        </div>
        <h4><i class="fas fa-list-ul"></i> Existing Subjects</h4>
        <div class="table-responsive"> <table class="data-table" id="subjects-table"> <thead> <tr> <th>#</th> <th>Name</th> <th>Code</th> <th>Type</th> <th>Created By</th> <th>Actions</th> </tr> </thead> <tbody>
            <?php
             $sql_fetch_subjects = "SELECT s.id, s.name, s.code, s.type, s.created_by_faculty_id, u.name as creator_name FROM subjects s LEFT JOIN users u ON s.created_by_faculty_id = u.id";
             $params_subjects = []; $types_subjects = "";
             if (!$is_admin && $logged_in_faculty_id) { $sql_fetch_subjects .= " WHERE s.created_by_faculty_id = ? OR s.created_by_faculty_id IS NULL"; $params_subjects[] = $logged_in_faculty_id; $types_subjects .= "i"; }
             elseif (!$is_admin && !$logged_in_faculty_id && $logged_in_role=='faculty') { $sql_fetch_subjects .= " WHERE s.created_by_faculty_id IS NULL"; }
             $sql_fetch_subjects .= " ORDER BY s.name";
             $stmt_subjects = $conn->prepare($sql_fetch_subjects); $subjects_data = [];
             if ($stmt_subjects) { if (!empty($types_subjects)) { $stmt_subjects->bind_param($types_subjects, ...$params_subjects); } if ($stmt_subjects->execute()) { $res_subjects = $stmt_subjects->get_result(); while ($row = $res_subjects->fetch_assoc()){ $subjects_data[] = $row; } $res_subjects->free(); } else { error_log("Subj List Exec Fail: ".$stmt_subjects->error); } $stmt_subjects->close(); } else { error_log("Subj List Prep Fail: ".$conn->error); }
             $s_sr = 1; if (!empty($subjects_data)) { foreach ($subjects_data as $sub) { $id=$sub['id'];$n=htmlspecialchars($sub['name']);$c=htmlspecialchars($sub['code']);$t=htmlspecialchars($sub['type']);$c_id=$sub['created_by_faculty_id'];$c_name=$sub['creator_name']?htmlspecialchars($sub['creator_name']):($c_id===null?'Admin/System':'Unknown'); $can_manage=($is_admin || ($c_id !== null && $c_id == $logged_in_faculty_id)); echo "<tr class='subject-row'><form method='post' style='display: contents;'><input type='hidden' name='subject_id' value='$id'><td class='sr-no-cell'>{$s_sr}</td><td><span class='display-value name-display' data-original-value='$n'>$n</span><input type='text' name='subject_name' value='$n' class='edit-input name-input form-control form-control-sm' ".($can_manage?'required':'readonly')." disabled></td><td><span class='display-value code-display' data-original-value='$c'>$c</span><input type='text' name='subject_code' value='$c' class='edit-input code-input form-control form-control-sm' ".($can_manage?'required':'readonly')." disabled></td><td><span class='display-value type-display' data-original-value='$t'>$t</span><select name='subject_type' class='edit-input type-input form-select form-select-sm' ".($can_manage?'required':'disabled')." disabled><option value='Theory'".($t=='Theory'?' selected':'').">Theory</option><option value='IPCC'".($t=='IPCC'?' selected':'').">IPCC</option><option value='Lab'".($t=='Lab'?' selected':'').">Lab</option></select></td><td>".$c_name."</td><td class='action-buttons'>"; if($can_manage){echo "<button type='button' class='btn btn-secondary btn-sm edit-subject-btn'><i class='fas fa-pencil-alt'></i> Edit</button><button type='submit' name='edit_subject' value='1' class='btn btn-primary btn-sm save-subject-btn' style='display: none;'><i class='fas fa-save'></i> Save</button><button type='button' class='btn btn-light btn-sm cancel-edit-subject-btn' style='display: none;'><i class='fas fa-times'></i> Cancel</button></form><form method='post' style='display: inline-block;'><input type='hidden' name='subject_id' value='$id'><button type='submit' name='delete_subject' value='1' class='btn btn-danger btn-sm delete-subject-btn' onclick=\"return confirm('DELETE Subject: ".htmlspecialchars($n, ENT_QUOTES)."?\\nWarning: May fail if marks exist.');\"><i class='fas fa-trash-alt'></i> Del</button></form>";} else { echo "</form><button type='button' class='btn btn-secondary btn-sm' disabled title='Cannot edit'><i class='fas fa-pencil-alt'></i> Edit</button><button type='button' class='btn btn-danger btn-sm' disabled title='Cannot delete'><i class='fas fa-trash-alt'></i> Del</button>";} echo "</td></tr>"; $s_sr++; } } else { $no_msg = $is_admin ? "No subjects found." : "No subjects found created by you or admin."; echo "<tr><td colspan='6' class='text-center text-muted'>$no_msg</td></tr>"; }
            ?>
        </tbody> </table> </div>
    </div>

    <?php if ($can_manage_students || $can_assign_subjects): ?>
    <div id="manageStudents" class="tab-content">
         <h3><i class="fas fa-users-cog"></i> Manage Students</h3>
         <?php if ($can_manage_students): ?>
             <div class="form-section">
                 <h4><i class="fas fa-user-plus"></i> Add New Student & Assign Initial Subjects</h4>
                 <form method="post"> <input type="hidden" name="add_student_and_subjects" value="1"> <legend>Student Details</legend> <div class="form-row"> <div class="form-col"><label for="add_student_name" class="form-label">Name</label><input type="text" id="add_student_name" name="name" required class="form-control" placeholder="Full name"></div> <div class="form-col"><label for="add_student_college_uid" class="form-label">College UID</label><input type="text" id="add_student_college_uid" name="college_uid" required class="form-control" placeholder="Unique college ID"></div> <div class="form-col"><label for="add_student_email" class="form-label">Email</label><input type="email" id="add_student_email" name="email" required class="form-control" placeholder="Student email"></div> </div> <div class="form-row"> <div class="form-col"><label for="add_student_usn" class="form-label">USN (Optional)</label><input type="text" id="add_student_usn" name="usn" style="text-transform: uppercase;" class="form-control" placeholder="University Serial Number"></div> <div class="form-col" style="max-width: 150px;"><label for="add_student_section" class="form-label">Section</label><input type="text" id="add_student_section" name="section" required maxlength="5" style="text-transform: uppercase;" class="form-control" placeholder="e.g., A"></div> <div class="form-col" style="max-width: 150px;"><label for="add_student_semester" class="form-label">Semester</label><input type="number" id="add_student_semester" name="semester" value="4" required min="1" max="8" class="form-control" placeholder="1-8"></div> <div class="form-col"><label for="add_student_department" class="form-label">Department</label><?php if ($is_admin): ?><input type="text" id="add_student_department" name="department" required class="form-control" placeholder="e.g., CSE"><?php else: ?><input type="text" id="add_student_department" name="department" required class="form-control" value="<?= htmlspecialchars($user_info['department'] ?? '') ?>" readonly title="Faculty add to own dept"><?php endif; ?></div> </div> <p class="form-text">Default Pass: '<?= htmlspecialchars($default_password_plain) ?>'. Needs change on login.</p> <?php if ($logged_in_role == 'faculty' && $logged_in_faculty_id): ?><legend style="margin-top: 20px;">Assign Initial Subjects (To You)</legend> <div class="form-row"> <div class="form-col"><label for="add_student_subjects" class="form-label">Select Your Subjects:</label><select id="add_student_subjects" name="subjects_to_assign[]" class="select2" multiple data-placeholder="Select subjects..."><?php $sql_assignable = "SELECT id, name, code, type FROM subjects WHERE created_by_faculty_id = ? OR created_by_faculty_id IS NULL ORDER BY type, name"; $stmt_assignable = $conn->prepare($sql_assignable); $loaded_assign = false; if ($stmt_assignable) { $stmt_assignable->bind_param("i", $logged_in_faculty_id); if ($stmt_assignable->execute()) { $res_assignable = $stmt_assignable->get_result(); if ($res_assignable->num_rows > 0) { while ($s_assign = $res_assignable->fetch_assoc()) { echo "<option value='{$s_assign['id']}'>".htmlspecialchars($s_assign['name'])." (".htmlspecialchars($s_assign['code']).") - [".htmlspecialchars($s_assign['type'])."]</option>"; } $loaded_assign = true; } $res_assignable->free(); } else { error_log("Exec assignable failed: ".$stmt_assignable->error); } $stmt_assignable->close(); } else { error_log("Prep assignable failed: ".$conn->error); } if (!$loaded_assign) { echo "<option value='' disabled>No assignable subjects found.</option>"; } ?></select><small class="form-text text-muted">Links marks entry to you.</small></div></div> <?php else: ?><input type="hidden" name="subjects_to_assign[]" value=""> <?php if($is_admin):?><p class="form-text text-muted"><i class="fas fa-info-circle"></i> Admins: Use 'Assign Subject' below after adding.</p><?php endif; ?><?php endif; ?> <div class="form-row"><div class="form-col-auto" style="margin-top: 15px;"><button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Student <?= ($logged_in_role == 'faculty' && $logged_in_faculty_id ? '& Assign' : '') ?></button></div></div> </form>
             </div>
         <?php endif; ?>
         <?php if ($can_assign_subjects): ?>
             <div class="form-section">
                 <h4><i class="fas fa-link"></i> Assign Subject to Existing Student</h4> <form method="post"> <input type="hidden" name="assign_subject" value="1"> <div class="form-row"> <div class="form-col"> <label for="student_select_assign" class="form-label">Select Student:</label> <select id="student_select_assign" name="assign_student_id" class="select2" required data-placeholder="-- Search & Select Student --"> <option value=""></option> <?php $assign_student_sql = "SELECT s.id, u.name, s.usn, s.department FROM students s JOIN users u ON s.user_id = u.id"; $assign_student_params = []; $assign_student_types = ''; if (!$is_admin && !empty($viewable_departments)) { $placeholders = implode(',', array_fill(0, count($viewable_departments), '?')); $assign_student_sql .= " WHERE s.department IN ($placeholders)"; $assign_student_params = $viewable_departments; $assign_student_types = str_repeat('s', count($viewable_departments)); } $assign_student_sql .= " ORDER BY u.name"; $stmt_assign_students = $conn->prepare($assign_student_sql); if($stmt_assign_students){ if(!empty($assign_student_types)){ $stmt_assign_students->bind_param($assign_student_types, ...$assign_student_params); } if($stmt_assign_students->execute()){ $res_assign_st=$stmt_assign_students->get_result(); while($row=$res_assign_st->fetch_assoc()){ echo "<option value='{$row['id']}'>".htmlspecialchars($row['name'])." (".htmlspecialchars($row['usn'] ?? 'N/A').") - Dept: ".htmlspecialchars($row['department'] ?? 'N/A')."</option>"; } $res_assign_st->free(); } else {error_log("Exec assign students dropdown fail: ".$stmt_assign_students->error);} $stmt_assign_students->close(); } else {error_log("Prep assign students dropdown fail: ".$conn->error);} ?> </select> </div> <div class="form-col"> <label for="assign_subject_id" class="form-label">Select Subject:</label> <select id="assign_subject_id" name="assign_subject_id" required class="select2" data-placeholder="-- Select Subject --"> <option value=""></option> <?php $res_subjects_list_assign = $conn->query("SELECT id, name, code, type FROM subjects ORDER BY name"); if ($res_subjects_list_assign) { while ($sub = $res_subjects_list_assign->fetch_assoc()) { echo "<option value='{$sub['id']}'>".htmlspecialchars($sub['name'])." (".htmlspecialchars($sub['code']).") - [".htmlspecialchars($sub['type'])."]</option>"; } $res_subjects_list_assign->free(); } ?> </select> </div> <div class="form-col-auto"> <button type="submit" class="btn btn-secondary"><i class="fas fa-check"></i> Assign</button> </div> </div> <?php if ($is_admin): ?><small class="form-text text-muted"><i class="fas fa-info-circle"></i> Admin assignment creates link; faculty handles marks.</small><?php elseif ($logged_in_faculty_id): ?><small class="form-text text-muted">Links marks entry to you.</small><?php endif; ?> </form>
             </div>
         <?php endif; ?>
         <?php if ($can_manage_students): ?>
             <h4><i class="fas fa-users"></i> Existing Students List</h4>
             <?php if ($is_admin && count($filter_departments_display) > 1): ?> <div class="department-filters"> <button class="btn btn-sm department-filter-btn active" data-filter-department="all">All Depts</button> <?php foreach ($filter_departments_display as $dept_filter): ?><button class="btn btn-sm department-filter-btn" data-filter-department="<?= htmlspecialchars($dept_filter) ?>"> <?= htmlspecialchars($dept_filter) ?> </button> <?php endforeach; ?> </div> <?php endif; ?>
             <div class="section-filters"> <button class="btn btn-sm section-filter-btn active" data-filter-section="all">All Sections</button> <?php $sections_sql = "SELECT DISTINCT section FROM students WHERE section IS NOT NULL AND section != ''"; $section_params = []; $section_types = ''; if (!$is_admin && !empty($viewable_departments)) { $placeholders = implode(',', array_fill(0, count($viewable_departments), '?')); $sections_sql .= " AND department IN ($placeholders)"; $section_params = $viewable_departments; $section_types = str_repeat('s', count($viewable_departments)); } $sections_sql .= " ORDER BY section"; $stmt_sections = $conn->prepare($sections_sql); $distinct_sections = []; if ($stmt_sections) { if (!empty($section_types)) { $stmt_sections->bind_param($section_types, ...$section_params); } if($stmt_sections->execute()){ $res_sections = $stmt_sections->get_result(); while ($sec = $res_sections->fetch_assoc()) { $clean_sec = trim($sec['section']); if (!empty($clean_sec) && !in_array($clean_sec, $distinct_sections)) { $u_sec = htmlspecialchars($clean_sec); echo "<button class='btn btn-sm section-filter-btn' data-filter-section='$u_sec'>Sec $u_sec</button>"; $distinct_sections[] = $clean_sec; } } $res_sections->free(); } else { error_log("Exec Sections Filter Fail: ".$stmt_sections->error); } $stmt_sections->close(); } else { error_log("Prep Sections Filter Fail: ".$conn->error); } ?> </div>
             <div class="table-responsive"><table class="data-table" id="students-table"><thead><tr><th>#</th><th>Name</th><th>USN</th><th>Section</th><th>Department</th><th>Actions</th></tr></thead><tbody>
                 <?php $student_list_sql = "SELECT s.id, s.usn, s.section, s.department, u.name, u.id as user_id FROM students s JOIN users u ON s.user_id = u.id"; $student_params = []; $student_types = ''; if (!$is_admin && !empty($viewable_departments)) { $placeholders = implode(',', array_fill(0, count($viewable_departments), '?')); $student_list_sql .= " WHERE s.department IN ($placeholders)"; $student_params = $viewable_departments; $student_types = str_repeat('s', count($viewable_departments)); } $student_list_sql .= " ORDER BY s.department, s.section, u.name"; $stmt_students = $conn->prepare($student_list_sql); $students_list_data = []; if($stmt_students){ if(!empty($student_types)){ $stmt_students->bind_param($student_types, ...$student_params); } if($stmt_students->execute()){ $res_st=$stmt_students->get_result(); while($row=$res_st->fetch_assoc()){ $students_list_data[] = $row; } $res_st->free(); } else {error_log("Exec students fail: ".$stmt_students->error);} $stmt_students->close(); } else {error_log("Prep students fail: ".$conn->error);} $st_sr=1; if(!empty($students_list_data)){ foreach($students_list_data as $stu) { $sid=$stu['id']; $s_userid=$stu['user_id']; $sn=htmlspecialchars($stu['name']); $su=htmlspecialchars($stu['usn'] ?? 'N/A'); $ss=htmlspecialchars(trim($stu['section'])); $sd=htmlspecialchars(trim($stu['department'] ?? '')); $can_edit_delete_this = ($is_admin || ($logged_in_role == 'faculty' && $sd == $faculty_dept_for_auth)); echo"<tr class='student-row' data-section='$ss' data-department='$sd'><form method='post' style='display: contents;'><input type='hidden' name='student_id' value='$sid'><td class='sr-no-cell'>{$st_sr}</td><td><span class='display-value name-display' data-original-value='$sn'>$sn</span><input type='text' name='name' value='$sn' class='edit-input name-input form-control form-control-sm' ".($can_edit_delete_this?'required':'readonly')." disabled></td><td><span class='display-value usn-display' data-original-value='$su'>$su</span><input type='text' name='usn' value='".($stu['usn'] ?? '')."' class='edit-input usn-input form-control form-control-sm' style='text-transform: uppercase;' ".($can_edit_delete_this?'':'readonly')." disabled></td><td><span class='display-value section-display' data-original-value='$ss'>$ss</span><input type='text' name='section' value='$ss' class='edit-input section-input form-control form-control-sm' ".($can_edit_delete_this?'required maxlength="5" style="text-transform: uppercase;"':'readonly')." disabled></td><td>$sd</td><td class='action-buttons'>"; if($can_edit_delete_this){ echo "<button type='button' class='btn btn-secondary btn-sm edit-student-btn'><i class='fas fa-pencil-alt'></i> Edit</button><button type='submit' name='edit_student' value='1' class='btn btn-primary btn-sm save-student-btn' style='display: none;'><i class='fas fa-save'></i> Save</button><button type='button' class='btn btn-light btn-sm cancel-edit-student-btn' style='display: none;'><i class='fas fa-times'></i> Cancel</button></form><form method='post' style='display: inline-block;'><input type='hidden' name='student_id' value='$sid'><button type='submit' name='delete_student' value='1' class='btn btn-danger btn-sm delete-student-btn' onclick=\"return confirm('DELETE Student: ".htmlspecialchars($sn,ENT_QUOTES)." ($su)?\\nAll data lost!');\"><i class='fas fa-trash-alt'></i> Del</button></form>";} else {echo "</form><small class='text-muted' title='Cannot edit/delete'>View Only</small>";} echo "</td></tr>"; $st_sr++;} } else { $no_st_msg = $is_admin ? "No students found." : "No students in your department."; echo "<tr><td colspan='6' class='text-center text-muted'>$no_st_msg</td></tr>"; } ?>
             </tbody></table><p id="no-students-message" style="display: none; text-align: center; padding: 20px;" class="text-muted">No students match filters.</p></div>
         <?php else: ?> <?php if(!$can_assign_subjects): ?><div class="message-area warning"><i class="fas fa-exclamation-triangle"></i> No permission to manage student list.</div><?php endif; ?> <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php // Marks Tabs - Only visible if logged in user is faculty AND has a faculty ID ?>
    <?php if ($logged_in_role == 'faculty' && $logged_in_faculty_id): ?>
        <div id="enterMarks" class="tab-content">
             <h3><i class="fas fa-keyboard"></i> Enter Student Marks</h3>
             <p class="text-muted small">Select a student from your department to load subjects assigned to you for marks entry.</p>
             <form method="post" class="form-section">
                <input type="hidden" name="enter_marks" value="1">
                <div class="form-row">
                    <div class="form-col">
                        <label for="student_select_enter" class="form-label">Select Student (Your Dept):</label>
                        <select id="student_select_enter" name="student_id" class="select2 student-marks-selector" required data-placeholder="-- Select Student --">
                            <option value=""></option> <?php // For placeholder ?>
                            <?php
                            // ** CORRECTED QUERY **: Fetch students in faculty's department(s)
                            $sql_students_for_faculty_dropdown = "SELECT s.id, u.name, s.usn
                                                                FROM students s
                                                                JOIN users u ON s.user_id = u.id";
                            $dropdown_params = [];
                            $dropdown_types = "";
                            if (!empty($viewable_departments)) { // Should always be true for faculty with dept
                                $placeholders = implode(',', array_fill(0, count($viewable_departments), '?'));
                                $sql_students_for_faculty_dropdown .= " WHERE s.department IN ($placeholders)";
                                $dropdown_params = $viewable_departments;
                                $dropdown_types = str_repeat('s', count($viewable_departments));
                            } else {
                                // Fallback if somehow faculty has no dept (should not happen with checks)
                                $sql_students_for_faculty_dropdown .= " WHERE 1=0"; // Show no students
                            }
                            $sql_students_for_faculty_dropdown .= " ORDER BY u.name";

                            $stmt_students_for_faculty = $conn->prepare($sql_students_for_faculty_dropdown);
                            $options_html = "";
                            if($stmt_students_for_faculty){
                                if (!empty($dropdown_types)) {
                                    $stmt_students_for_faculty->bind_param($dropdown_types, ...$dropdown_params);
                                }
                                if ($stmt_students_for_faculty->execute()) {
                                    $res_students_faculty = $stmt_students_for_faculty->get_result();
                                    while($r_st = $res_students_faculty->fetch_assoc()){
                                        $selected_attr = (isset($_POST['student_id']) && $_POST['student_id'] == $r_st['id']) ? 'selected' : '';
                                        $options_html .= "<option value='{$r_st['id']}' $selected_attr>" . htmlspecialchars($r_st['name']) . " (" . htmlspecialchars($r_st['usn'] ?? 'N/A') . ")</option>";
                                    }
                                    $res_students_faculty->free();
                                } else { error_log("Exec failed students for Enter Marks dropdown: ".$stmt_students_for_faculty->error); }
                                $stmt_students_for_faculty->close();
                            } else { error_log("Prep failed students for Enter Marks dropdown: ".$conn->error); }

                            if(empty($options_html)){
                                echo "<option value='' disabled>No students found in your department.</option>";
                            } else {
                                echo $options_html;
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <hr>
                <h4><i class="fas fa-clipboard-list"></i> Assigned Subjects & Marks Fields</h4>
                 <!-- AJAX loaded fields will appear here -->
                 <div id="marks-entry-fields">
                     <?php
                     // If the form was submitted, trigger JS to load fields for the submitted student ID
                     if (isset($_POST['enter_marks']) && isset($_POST['student_id']) && filter_var($_POST['student_id'], FILTER_VALIDATE_INT)) {
                          echo '<script> $(document).ready(function(){ loadMarksFields('.intval($_POST['student_id']).'); });</script>';
                     } else {
                          // Initial state or if no student selected
                          echo '<p class="message-area info">Select a student above to load the subjects assigned to you for marks entry.</p>';
                     }
                     ?>
                 </div>
                 <button type="submit" class="btn btn-primary btn-lg mt-3"><i class="fas fa-check-circle"></i> Submit/Update Marks</button>
             </form>
             <?php // Display confirmation table if marks were submitted
                if (!empty($submitted_marks_html)) { echo $submitted_marks_html; }
             ?>
        </div>

        <div id="editMarks" class="tab-content">
             <h3><i class="fas fa-pen-square"></i> View / Edit Assigned Marks</h3>
             <p class="text-muted small">Select a student from your department to view and edit marks entries currently assigned to you.</p>
             <form method="post" class="form-section">
                 <div class="form-row">
                     <div class="form-col">
                         <label for="student_select_edit" class="form-label">Select Student (Your Dept):</label>
                         <select id="student_select_edit" name="student_id_select" class="select2" required data-placeholder="-- Select Student --">
                              <option value=""></option> <?php // For placeholder ?>
                              <?php
                              // ** CORRECTED QUERY **: Use the same corrected logic as Enter Marks dropdown
                              $stmt_students_for_edit_dropdown = $conn->prepare($sql_students_for_faculty_dropdown); // Reuse the SQL from above
                              $options_html_edit = "";
                              if($stmt_students_for_edit_dropdown){
                                  if (!empty($dropdown_types)) { // Reuse params/types from above
                                      $stmt_students_for_edit_dropdown->bind_param($dropdown_types, ...$dropdown_params);
                                  }
                                  if ($stmt_students_for_edit_dropdown->execute()) {
                                      $res_students_faculty_edit = $stmt_students_for_edit_dropdown->get_result();
                                      while($r_st_edit = $res_students_faculty_edit->fetch_assoc()){
                                          $selected_edit = (isset($_POST['student_id_select']) && $_POST['student_id_select'] == $r_st_edit['id']) ? 'selected' : '';
                                          $options_html_edit .= "<option value='{$r_st_edit['id']}' $selected_edit>" . htmlspecialchars($r_st_edit['name']) . " (" . htmlspecialchars($r_st_edit['usn'] ?? 'N/A') . ")</option>";
                                      }
                                      $res_students_faculty_edit->free();
                                  } else { error_log("Exec failed students for Edit Marks dropdown: ".$stmt_students_for_edit_dropdown->error); }
                                  $stmt_students_for_edit_dropdown->close();
                              } else { error_log("Prep failed students for Edit Marks dropdown: ".$conn->error); }

                              if(empty($options_html_edit)){ echo "<option value='' disabled>No students found in your department.</option>"; }
                              else { echo $options_html_edit; }
                              ?>
                         </select>
                     </div>
                     <div class="form-col-auto">
                         <button type="submit" name="load_marks" value="1" class="btn btn-secondary"><i class="fas fa-sync-alt"></i> Load Marks</button>
                     </div>
                 </div>
             </form>

             <?php
             // Display marks editing table if load_marks was pressed OR if an update/delete just happened
             if ((isset($_POST['load_marks']) || isset($_POST['update_all_marks']) || isset($_POST['delete_marks_entry'])) && !empty($_POST['student_id_select'])) {
                 $student_id_to_load = filter_var($_POST['student_id_select'], FILTER_VALIDATE_INT);
                 if ($student_id_to_load) {
                     // Get Student Name/USN for display
                     $student_name_usn="Student ID: $student_id_to_load";
                     $stmt_sd=$conn->prepare("SELECT u.name, s.usn FROM students s JOIN users u ON s.user_id=u.id WHERE s.id=?");
                     if($stmt_sd){$stmt_sd->bind_param("i",$student_id_to_load);$stmt_sd->execute();$res_sd=$stmt_sd->get_result();if($row_sd=$res_sd->fetch_assoc()){$student_name_usn=htmlspecialchars($row_sd['name'])." (".htmlspecialchars($row_sd['usn']??'N/A').")";}$stmt_sd->close();}

                     // Fetch marks data assigned to THIS faculty for the selected student
                     $marks_data_stmt = $conn->prepare(
                        "SELECT m.id as mark_id, m.subject_id, m.ia1, m.ia2, m.assignment, m.lab_record1, m.lab_ia1, m.lab_record2, m.lab_ia2, m.total_marks,
                                s.name AS sn, s.code sc, s.type st
                         FROM marks m
                         JOIN subjects s ON m.subject_id = s.id
                         WHERE m.student_id = ? AND m.faculty_id = ?
                         ORDER BY s.type, s.name");

                     if($marks_data_stmt){
                         $marks_data_stmt->bind_param("ii", $student_id_to_load, $logged_in_faculty_id);
                         $marks_data_stmt->execute();
                         $results = $marks_data_stmt->get_result();
                         $m_sr=1;

                         if($results->num_rows > 0){
                             echo "<form method='post' class='form-section'>";
                             echo "<input type='hidden' name='student_id_select' value='$student_id_to_load'>";
                             echo "<input type='hidden' name='student_id' value='$student_id_to_load'>";

                             echo "<h4><i class='fas fa-edit'></i> Editing Marks for: $student_name_usn</h4>";
                             echo "<div class='table-responsive'><table class='data-table'><thead><tr><th>#</th><th>Subject</th><th>Code</th><th>Type</th><th>IA1 <small>(25)</small></th><th>IA2 <small>(25)</small></th><th>Assign <small>(25)</small></th><th>Rec1 <small>(15)</small></th><th>LIA1 <small>(10)</small></th><th>Rec2 <small>(15)</small></th><th>LIA2 <small>(10)</small></th><th>Total</th><th>Action</th></tr></thead><tbody>";

                             $display_mark = function($value) { return ($value === null || $value === '') ? '' : htmlspecialchars($value); };

                             while($row = $results->fetch_assoc()){
                                 $sid = $row['subject_id']; $st = $row['st'];
                                 $is_lab_only = ($st == 'Lab'); $is_theory_only = ($st == 'Theory'); $is_not_lab = ($st != 'Lab'); $is_not_theory = ($st != 'Theory');
                                 echo "<tr><td>{$m_sr}</td><td>".htmlspecialchars($row['sn'])."</td><td>".htmlspecialchars($row['sc'])."</td><td>".htmlspecialchars($st)."</td>";
                                 echo "<td><input type='number' name='edit_marks[$sid][ia1]' value='".$display_mark($row['ia1'])."' min='0' max='25' step='0.5' class='form-control form-control-sm' ".($is_lab_only?'disabled':'')." title='IA1 (Max 25)'></td>";
                                 echo "<td><input type='number' name='edit_marks[$sid][ia2]' value='".$display_mark($row['ia2'])."' min='0' max='25' step='0.5' class='form-control form-control-sm' ".($is_lab_only?'disabled':'')." title='IA2 (Max 25)'></td>";
                                 echo "<td><input type='number' name='edit_marks[$sid][assignment]' value='".$display_mark($row['assignment'])."' min='0' max='25' step='0.5' class='form-control form-control-sm' ".($is_lab_only?'disabled':'')." title='Assignment (Max 25)'></td>";
                                 echo "<td><input type='number' name='edit_marks[$sid][record1]' value='".$display_mark($row['lab_record1'])."' min='0' max='15' step='0.5' class='form-control form-control-sm' ".($is_theory_only?'disabled':'')." title='Lab Record 1 (Max 15)'></td>";
                                 echo "<td><input type='number' name='edit_marks[$sid][lab_ia1]' value='".$display_mark($row['lab_ia1'])."' min='0' max='10' step='0.5' class='form-control form-control-sm' ".($is_theory_only?'disabled':'')." title='Lab IA 1 (Max 10)'></td>";
                                 echo "<td><input type='number' name='edit_marks[$sid][record2]' value='".$display_mark($row['lab_record2'])."' min='0' max='15' step='0.5' class='form-control form-control-sm' ".($is_not_lab?'disabled':'')." title='Lab Record 2 (Max 15) - Lab Only'></td>";
                                 echo "<td><input type='number' name='edit_marks[$sid][lab_ia2]' value='".$display_mark($row['lab_ia2'])."' min='0' max='10' step='0.5' class='form-control form-control-sm' ".($is_not_lab?'disabled':'')." title='Lab IA 2 (Max 10) - Lab Only'></td>";
                                 echo "<td><strong>".($row['total_marks'] === null ? '-' : htmlspecialchars($row['total_marks']))."</strong></td>";
                                 echo "<td class='action-buttons'><button type='submit' name='delete_marks_entry' value='$sid' class='btn btn-danger btn-sm' onclick=\"return confirm('Delete marks for ".htmlspecialchars($row['sn'], ENT_QUOTES)."?');\"><i class='fas fa-trash-alt'></i> Del</button></td></tr>";
                                 $m_sr++;
                             }
                             echo "</tbody></table></div><br>";
                             echo "<button type='submit' name='update_all_marks' value='1' class='btn btn-primary btn-lg'><i class='fas fa-save'></i> Update All Displayed Marks</button>";
                             echo "</form>";
                         } else {
                             echo "<p class='message-area info'>No marks found assigned by you for the selected student ($student_name_usn). Use the 'Manage Students' tab to assign subjects first.</p>";
                         }
                         $results->free();
                         $marks_data_stmt->close();
                     } else {
                         echo "<p class='message-area error'>Error fetching marks data: ".$conn->error."</p>";
                         error_log("Error preparing marks fetch for edit: ".$conn->error);
                     }
                 } else {
                      echo "<p class='message-area error'>Invalid student selected.</p>";
                 }
             } elseif (isset($_POST['load_marks']) && empty($_POST['student_id_select'])) {
                 echo "<p class='message-area error'>Please select a student from the dropdown to load their marks.</p>";
             }
             ?>
        </div>
    <?php endif; // End check for faculty role for marks tabs ?>

</div> <!-- End Dashboard Container -->

<script>
    // Tab switching function
    function openTab(evt, tabId) {
        var i, tabcontent, tablinks;
        tabcontent = document.getElementsByClassName("tab-content");
        for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; tabcontent[i].classList.remove("active"); }
        tablinks = document.getElementsByClassName("tab-link");
        for (i = 0; i < tablinks.length; i++) { tablinks[i].classList.remove("active"); }
        const targetTab = document.getElementById(tabId);
         if (targetTab) { targetTab.style.display = "block"; setTimeout(() => targetTab.classList.add("active"), 10); }
         if (evt && evt.currentTarget) { evt.currentTarget.classList.add("active"); }
         localStorage.setItem('activeDashboardTab_<?= $logged_in_role ?>', tabId);
    }

    // Function to load marks fields via AJAX (for Enter Marks tab)
    function loadMarksFields(studentId) {
        const container = $('#marks-entry-fields');
        if (!studentId) { container.html('<p class="message-area info">Select a student.</p>'); return; }
        container.html('<p class="message-area info"><i class="fas fa-spinner fa-spin"></i> Loading...</p>');
        $.ajax({
            url: 'ajax_get_marks_fields.php', // MAKE SURE THIS FILE EXISTS AND IS CORRECT
            method: 'POST', data: { student_id: studentId },
            success: function(response) {
                container.html(response);
                if (response.trim() === '' || response.includes("No subjects assigned")) {
                     container.html('<p class="message-area warning"><i class="fas fa-exclamation-circle"></i> No subjects assigned to you for this student. Use \'Assign Subject\' in Manage Students tab.</p>');
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX Error loading marks:", status, error, xhr.responseText);
                container.html('<p class="message-area error"><i class="fas fa-times-circle"></i> Error loading. Ensure <code style="font-size:0.8em">ajax_get_marks_fields.php</code> exists & check console.</p>');
            }
        });
    }

    // Document Ready function
    $(document).ready(function() {
        // Initialize Select2
        $('.select2').select2({
             placeholder: $(this).data('placeholder') || "-- Select --",
             allowClear: true, width: 'resolve',
        });

        // Load marks fields on change
        $('#student_select_enter').on('change', function() { loadMarksFields($(this).val()); });

        // Tab persistence
        const activeTabId = localStorage.getItem('activeDashboardTab_<?= $logged_in_role ?>') || 'dashboardInfo';
        const targetTabElement = document.getElementById(activeTabId);
        const targetButton = document.querySelector(`.tab-link[onclick*="openTab(event, '${activeTabId}')"]`);
        if (targetTabElement && targetButton) { openTab({ currentTarget: targetButton }, activeTabId); }
        else { const defaultOpenButton = document.getElementById("defaultOpen"); if (defaultOpenButton) { defaultOpenButton.click(); } }

        // Auto-close messages
        if ($('.message-area').length) {
             setTimeout(function() { $('.message-area.success, .message-area.info, .message-area.warning').fadeOut('slow'); }, 6000);
             setTimeout(function() { $('.message-area.error').fadeOut('slow'); }, 10000);
        }

        // --- Combined Filtering Logic ---
        let currentDeptFilter = 'all'; let currentSectionFilter = 'all';
        const $studentRows = $('#students-table tbody .student-row'); const $noStudentsMsg = $('#no-students-message');
        function applyFilters() { let visibleCount = 0; let currentSrNo = 1; $noStudentsMsg.hide(); $studentRows.each(function() { const rowDept = $(this).data('department'); const rowSection = $(this).data('section'); const deptMatch = (currentDeptFilter === 'all' || rowDept == currentDeptFilter); const sectionMatch = (currentSectionFilter === 'all' || rowSection == currentSectionFilter); if (deptMatch && sectionMatch) { $(this).show().find('td.sr-no-cell').text(currentSrNo++); visibleCount++; } else { $(this).hide(); } }); if (visibleCount === 0 && $studentRows.length > 0) { let filterText = []; if (currentDeptFilter !== 'all') filterText.push(`Dept: ${currentDeptFilter}`); if (currentSectionFilter !== 'all') filterText.push(`Sec: ${currentSectionFilter}`); $noStudentsMsg.text(`No students match filters (${filterText.join(', ')})`).show(); } else if (visibleCount === 0 && $studentRows.length === 0) { /* Initial empty message handled by PHP */ } }
        $('.department-filter-btn').on('click', function() { $('.department-filter-btn').removeClass('active'); $(this).addClass('active'); currentDeptFilter = $(this).data('filter-department'); applyFilters(); });
        $('.section-filter-btn').on('click', function() { $('.section-filter-btn').removeClass('active'); $(this).addClass('active'); currentSectionFilter = $(this).data('filter-section'); applyFilters(); });

        // --- Inline Edit Toggles ---
        $('#students-table tbody, #subjects-table tbody').on('click', '.edit-student-btn, .edit-subject-btn', function() { const $r = $(this).closest('tr'); $r.find('.display-value').hide(); $r.find('.edit-input').show().prop('disabled', false).first().focus(); if ($(this).hasClass('edit-student-btn')) { /* Optional: specific logic for student edit */ } $(this).hide(); $r.find('.save-student-btn, .save-subject-btn, .cancel-edit-student-btn, .cancel-edit-subject-btn').show(); $r.find('.delete-student-btn, .delete-subject-btn').closest('form').hide(); });
        $('#students-table tbody, #subjects-table tbody').on('click', '.cancel-edit-student-btn, .cancel-edit-subject-btn', function() { const $r = $(this).closest('tr'); $r.find('.edit-input').hide().prop('disabled', true); $r.find('.display-value').show(); $r.find('.edit-input').each(function(){const o=$(this).siblings('.display-value').data('original-value'); $(this).val(o);}); $r.find('.save-student-btn, .save-subject-btn, .cancel-edit-student-btn, .cancel-edit-subject-btn').hide(); $r.find('.edit-student-btn, .edit-subject-btn').show(); $r.find('.delete-student-btn, .delete-subject-btn').closest('form').show(); });

        // Scroll to marks table if applicable
        <?php if(($logged_in_role == 'faculty') && (isset($_POST['load_marks']) || isset($_POST['update_all_marks']) || isset($_POST['delete_marks_entry'])) && !empty($_POST['student_id_select'])): ?>
             if ($('#editMarks').hasClass('active') || localStorage.getItem('activeDashboardTab_faculty') === 'editMarks') {
                 const $eT=$("#editMarks .form-section:has(table.data-table)");
                 if($eT.length){$('html, body').animate({scrollTop:$eT.offset().top-80},500);}
             }
        <?php endif; ?>
    });
</script>

</body>
</html>