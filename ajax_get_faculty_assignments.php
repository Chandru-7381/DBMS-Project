<?php
session_start();
require 'includes/db.php';

// --- Authentication: Ensure user is Admin ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Content-Type: application/json');
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// --- Validate Input ---
$faculty_id = filter_input(INPUT_POST, 'faculty_id', FILTER_VALIDATE_INT);

if (!$faculty_id) {
    header('Content-Type: application/json');
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid Faculty ID']);
    exit;
}

// --- Fetch Assigned Subject IDs ---
$assigned_subject_ids = [];
$stmt = $conn->prepare("SELECT subject_id FROM faculty_subjects WHERE faculty_id = ?");

if ($stmt) {
    $stmt->bind_param("i", $faculty_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $assigned_subject_ids[] = $row['subject_id'];
        }
    } else {
        error_log("AJAX Get Assignments Execute Error: " . $stmt->error);
        // Don't send specific DB errors to client
    }
    $stmt->close();
} else {
     error_log("AJAX Get Assignments Prepare Error: " . $conn->error);
}
$conn->close();

// --- Return JSON Response ---
header('Content-Type: application/json');
echo json_encode(['assigned_ids' => $assigned_subject_ids]);
exit;
?>