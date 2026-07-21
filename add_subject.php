<?php
require 'includes/db.php';
session_start();

// Handle subject form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_subject'])) {
    $name = $_POST['name'];
    $code = $_POST['code'];
    $type = $_POST['type'];

    $stmt = $conn->prepare("INSERT INTO subjects (name, code, type) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $name, $code, $type);
    
    if ($stmt->execute()) {
        echo "<p style='color:green;'>Subject added successfully!</p>";
    } else {
        echo "<p style='color:red;'>Error: " . $conn->error . "</p>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Subject</title>
    <style>
        .form-section { margin-bottom: 20px; }
        .hidden { display: none; }
    </style>
    <script>
        function showFields() {
            const type = document.getElementById("type").value;
            document.querySelectorAll('.mark-fields').forEach(el => el.classList.add('hidden'));
            if (type === "Theory") document.getElementById("theory-fields").classList.remove('hidden');
            else if (type === "IPCC") document.getElementById("ipcc-fields").classList.remove('hidden');
            else if (type === "Lab") document.getElementById("lab-fields").classList.remove('hidden');
        }
    </script>
</head>
<body>

<h2>Add New Subject</h2>

<form method="post">
    <div class="form-section">
        <label>Subject Name:</label><br>
        <input type="text" name="name" required><br><br>

        <label>Subject Code:</label><br>
        <input type="text" name="code" required><br><br>

        <label>Subject Type:</label><br>
        <select name="type" id="type" onchange="showFields()" required>
            <option value="">Select Type</option>
            <option value="Theory">Theory</option>
            <option value="IPCC">IPCC</option>
            <option value="Lab">Lab</option>
        </select>
    </div>

    <!-- Mark Fields Display Based on Type -->
    <div id="theory-fields" class="mark-fields hidden">
        <strong>Theory Mark Format:</strong>
        <ul>
            <li>IA1 (25)</li>
            <li>IA2 (25)</li>
            <li>Assignment (25)</li>
        </ul>
    </div>

    <div id="ipcc-fields" class="mark-fields hidden">
        <strong>IPCC Mark Format:</strong>
        <ul>
            <li>IA1 (25)</li>
            <li>IA2 (25)</li>
            <li>Assignment (10)</li>
            <li>Lab Record (10)</li>
            <li>Lab IA (5)</li>
        </ul>
    </div>

    <div id="lab-fields" class="mark-fields hidden">
        <strong>Lab Mark Format:</strong>
        <ul>
            <li>Record1 (10)</li>
            <li>Lab IA1 (15)</li>
            <li>Record2 (10)</li>
            <li>Lab IA2 (15)</li>
        </ul>
    </div>

    <br>
    <button type="submit" name="add_subject">Add Subject</button>
</form>

</body>
</html>
