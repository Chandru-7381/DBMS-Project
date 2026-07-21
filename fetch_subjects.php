<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require 'includes/db.php';
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$logged_in_faculty_id = $_SESSION['user_id'];

// Validate and sanitize input
$department = filter_input(INPUT_GET, 'department', FILTER_SANITIZE_STRING);

if (empty($department)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid department']);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, name, code, type
    FROM subjects
    WHERE department = ? AND (created_by_faculty_id = ? OR created_by_faculty_id IS NULL)
    ORDER BY type, name
");

if ($stmt) {
    $stmt->bind_param("si", $department, $logged_in_faculty_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $subjects = [];
        while ($row = $result->fetch_assoc()) {
            $subjects[] = $row;
        }
        $stmt->close();

        if (!empty($subjects)) {
            http_response_code(200); // OK
            echo json_encode($subjects);
        } else {
            http_response_code(404); // Not Found
            echo json_encode([]);
        }
    } else {
        error_log("Error executing query in fetch_subjects.php: " . $stmt->error);
        http_response_code(500); // Internal Server Error
        echo json_encode(['error' => 'Failed to fetch subjects']);
    }
} else {
    error_log("Error preparing query in fetch_subjects.php: " . $conn->error);
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'Failed to prepare query']);
}
?>