<?php
session_start(); // Start the session to access logged-in user data
require 'includes/db.php'; // Make sure this path is correct

header('Content-Type: text/html; charset=utf-8'); // Set correct content type for HTML response

// --- Authentication Check & Get Faculty ID ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'faculty' || !isset($_POST['student_id'])) {
    http_response_code(401); // Unauthorized or 400 Bad Request
    echo '<p class="message-area error">Error: Unauthorized access or missing data.</p>';
    exit;
}
$logged_in_faculty_id = $_SESSION['user_id']; // Get the logged-in faculty's ID
// --- End Authentication ---

// --- Validate Student ID ---
$student_id = filter_var($_POST['student_id'], FILTER_VALIDATE_INT);
if (!$student_id) {
    http_response_code(400);
    echo '<p class="message-area error">Error: Invalid student ID provided.</p>';
    exit;
}
// --- End Validation ---

// --- Database Query with Faculty Filter ---
// Fetch assigned subjects AND existing marks, filtering by BOTH student_id AND faculty_id
$stmt = $conn->prepare("SELECT
                            s.id AS subject_id, s.name AS subject_name, s.code, s.type,
                            m.ia1, m.ia2, m.assignment, m.lab_record1, m.lab_ia1,
                            m.lab_record2, m.lab_ia2, m.total_marks, m.id as mark_id
                        FROM marks m
                        JOIN subjects s ON m.subject_id = s.id
                        WHERE m.student_id = ? AND m.faculty_id = ? -- Added faculty_id filter
                        ORDER BY s.type, s.name");

if (!$stmt) {
    error_log("Prepare failed (ajax_get_marks_fields): " . $conn->error);
    http_response_code(500); // Internal Server Error
    echo '<p class="message-area error">Error preparing statement to fetch marks.</p>';
    exit;
}

// Bind BOTH student_id and the logged_in_faculty_id
$stmt->bind_param("ii", $student_id, $logged_in_faculty_id);

if (!$stmt->execute()) {
    error_log("Execute failed (ajax_get_marks_fields): " . $stmt->error);
    http_response_code(500);
    echo '<p class="message-area error">Error executing statement to fetch marks.</p>';
    $stmt->close();
    exit;
}

$result = $stmt->get_result();
$output_html = '';

// --- Generate HTML Output ---
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $sub_id = $row['subject_id'];
        $sub_name = htmlspecialchars($row['subject_name']);
        $sub_code = htmlspecialchars($row['code']);
        $sub_type = $row['type'];
        $mark_id = $row['mark_id']; // Unique ID for the marks row

        // Determine which fields are applicable and max values
        $is_lab_only = ($sub_type == 'Lab');
        $is_theory_only = ($sub_type == 'Theory');
        $is_ipcc = ($sub_type == 'IPCC');

        // Define Max values (Adjust these based on your exact scheme)
        $max_ia = 25;
        $max_assign_theory = 25;
        $max_assign_ipcc = 10; // Example
        $max_rec1_lab = 15; // Example for Lab
        $max_rec1_ipcc = 10; // Example for IPCC
        $max_lia1_lab = 10; // Example for Lab
        $max_lia1_ipcc = 5;  // Example for IPCC
        $max_rec2_lab = 15;
        $max_lia2_lab = 10;

        // Helper function for display
        $display_mark = fn($mark) => ($mark === null || $mark === '') ? '' : htmlspecialchars($mark);

        $output_html .= "<fieldset class='subject-marks-entry'>";
        $output_html .= "<legend>" . $sub_name . " (" . $sub_code . ") - Type: " . $sub_type . "</legend>";
        $output_html .= "<input type='hidden' name='subject_marks[$sub_id][type]' value='$sub_type'>";
        $output_html .= "<div class='marks-grid'>";

        // --- Input Fields (Added form-control class) ---

        // IA1 & IA2 (Disabled for Lab Only)
        $output_html .= "<div class='mark-item'><label for='ia1_$sub_id'>IA 1 <small>(Max $max_ia)</small></label><input type='number' id='ia1_$sub_id' name='subject_marks[$sub_id][ia1]' value='" . $display_mark($row['ia1']) . "' min='0' max='$max_ia' step='0.5' class='form-control' " . ($is_lab_only ? 'disabled' : '') . "></div>";
        $output_html .= "<div class='mark-item'><label for='ia2_$sub_id'>IA 2 <small>(Max $max_ia)</small></label><input type='number' id='ia2_$sub_id' name='subject_marks[$sub_id][ia2]' value='" . $display_mark($row['ia2']) . "' min='0' max='$max_ia' step='0.5' class='form-control' " . ($is_lab_only ? 'disabled' : '') . "></div>";

        // Assignment (Different Max for Theory/IPCC, Disabled for Lab Only)
        $assign_max = $is_lab_only ? 0 : ($is_ipcc ? $max_assign_ipcc : $max_assign_theory);
        $output_html .= "<div class='mark-item'><label for='assign_$sub_id'>Assignment <small>(Max " . $assign_max . ")</small></label><input type='number' id='assign_$sub_id' name='subject_marks[$sub_id][assignment]' value='" . $display_mark($row['assignment']) . "' min='0' max='" . $assign_max . "' step='0.5' class='form-control' " . ($is_lab_only ? 'disabled' : '') . "></div>";

        // Record 1 & Lab IA 1 (Different Max for Lab/IPCC, Disabled for Theory Only)
        $rec1_max = $is_theory_only ? 0 : ($is_ipcc ? $max_rec1_ipcc : $max_rec1_lab);
        $lia1_max = $is_theory_only ? 0 : ($is_ipcc ? $max_lia1_ipcc : $max_lia1_lab);
        $output_html .= "<div class='mark-item'><label for='rec1_$sub_id'>Lab Record 1 <small>(Max " . $rec1_max . ")</small></label><input type='number' id='rec1_$sub_id' name='subject_marks[$sub_id][record1]' value='" . $display_mark($row['lab_record1']) . "' min='0' max='" . $rec1_max . "' step='0.5' class='form-control' " . ($is_theory_only ? 'disabled' : '') . "></div>";
        $output_html .= "<div class='mark-item'><label for='lab_ia1_$sub_id'>Lab IA 1 <small>(Max " . $lia1_max . ")</small></label><input type='number' id='lab_ia1_$sub_id' name='subject_marks[$sub_id][lab_ia1]' value='" . $display_mark($row['lab_ia1']) . "' min='0' max='" . $lia1_max . "' step='0.5' class='form-control' " . ($is_theory_only ? 'disabled' : '') . "></div>";

        // Record 2 & Lab IA 2 (Only for Lab)
        $rec2_max = $is_lab_only ? $max_rec2_lab : 0;
        $lia2_max = $is_lab_only ? $max_lia2_lab : 0;
        $output_html .= "<div class='mark-item'><label for='rec2_$sub_id'>Lab Record 2 <small>(Max " . $rec2_max . ")</small></label><input type='number' id='rec2_$sub_id' name='subject_marks[$sub_id][record2]' value='" . $display_mark($row['lab_record2']) . "' min='0' max='" . $rec2_max . "' step='0.5' class='form-control' " . (!$is_lab_only ? 'disabled' : '') . "></div>";
        $output_html .= "<div class='mark-item'><label for='lab_ia2_$sub_id'>Lab IA 2 <small>(Max " . $lia2_max . ")</small></label><input type='number' id='lab_ia2_$sub_id' name='subject_marks[$sub_id][lab_ia2]' value='" . $display_mark($row['lab_ia2']) . "' min='0' max='" . $lia2_max . "' step='0.5' class='form-control' " . (!$is_lab_only ? 'disabled' : '') . "></div>";

        $output_html .= "</div>"; // end marks-grid
        $output_html .= "</fieldset>";
    }
} else {
    // Updated "no rows" message
    $output_html = '<p class="message-area warning">No subjects assigned *to you* for this student. Please use the \'Manage Students\' tab to assign subjects first.</p>';
}

$stmt->close();
$conn->close();

echo $output_html; // Output the generated HTML
?>