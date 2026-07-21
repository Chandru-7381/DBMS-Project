<?php
session_start();
require 'includes/db.php';


// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}


$studentData = null;
$marksData = [];
$error = null;

// Get student info based on logged-in user
$stmt = $conn->prepare("SELECT s.id AS id, s.usn, s.section, s.department, s.semester, u.name
                       FROM students s
                       JOIN users u ON s.user_id = u.id
                       WHERE s.user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();


if ($result->num_rows > 0) {
    $studentData = $result->fetch_assoc();
    $student_id = $studentData['id'];

    // Fetch subjects and marks
    $query = $conn->prepare("SELECT sub.name AS subject_name, sub.code, sub.type,
                            m.ia1, m.ia2, m.assignment,
                            m.lab_record1, m.lab_ia1,
                            m.lab_record2, m.lab_ia2
                            FROM marks m
                            JOIN subjects sub ON m.subject_id = sub.id
                            WHERE m.student_id = ?");
    $query->bind_param("i", $student_id);
    $query->execute();
    $marksResult = $query->get_result();

    if ($marksResult->num_rows > 0) {
        $marksData = $marksResult->fetch_all(MYSQLI_ASSOC);
    } else {
        // Changed the message slightly for clarity
        $error = "No marks records found for this semester yet.";
    }
} else {
    $error = "Student record not found. Please contact administration.";
}

// Function to return 'NA' if zero/null
function showMark($value) {
    // Check for both 0 and null explicitly if needed, or just falsy values
    return ($value === null || $value === 0 || $value === '') ? 'NA' : htmlspecialchars($value);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Academic Record</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --accent-color: #4cc9f0;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --success-color: #4bb543;
            --danger-color: #ff3333; /* Used for logout potentially, or errors */
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f7fa;
            color: var(--dark-color);
            line-height: 1.6;
            position: relative; /* Needed for absolute positioning of child elements like logout */
            min-height: 100vh; /* Ensure body takes full height */
        }

        /* <<< --- ADDED CSS FOR LOGOUT BUTTON --- >>> */
        .logout-button {
            position: absolute;
            top: 1rem; /* Adjust spacing from top */
            right: 1rem; /* Adjust spacing from right */
            background-color: var(--danger-color); /* Use danger color for logout */
            color: white;
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            z-index: 1000; /* Ensure it's above other content */
        }

        .logout-button:hover {
            background-color: #cc2929; /* Darker shade on hover */
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        /* <<< --- END OF ADDED CSS --- >>> */


        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 4rem 2rem 2rem 2rem; /* Increased top padding to avoid overlap with logout button */
        }

        .header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .header h1 {
            color: var(--primary-color);
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .error-message {
            background-color: #ffebee;
            color: var(--danger-color);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            text-align: center;
        }

        .student-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
        }

        .student-info {
            flex: 1;
            min-width: 300px;
        }

        .student-info h2 {
            color: var(--secondary-color);
            margin-bottom: 1rem;
            font-size: 1.8rem;
        }

        .student-details {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .detail-item {
            background-color: var(--light-color);
            padding: 0.8rem;
            border-radius: var(--border-radius);
        }

        .detail-item strong {
            display: block;
            color: var(--secondary-color);
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .marks-table-container { /* Added a container for potential overflow styling */
             width: 100%;
             overflow-x: auto; /* Ensure table is scrollable on small screens */
        }

        .marks-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden; /* Helps with border-radius */
            margin-top: 2rem;
            min-width: 800px; /* Minimum width to prevent excessive squishing */
        }

        .marks-table thead {
            background-color: var(--primary-color);
            color: white;
        }

        .marks-table th {
            padding: 1rem;
            text-align: center;
            font-weight: 500;
            white-space: nowrap; /* Prevent header text wrapping */
        }

        .marks-table td {
            padding: 0.8rem;
            text-align: center;
            border-bottom: 1px solid #eee;
             white-space: nowrap; /* Prevent cell content wrapping */
        }

        .marks-table tr:last-child td {
            border-bottom: none;
        }

        .marks-table tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }

        /* Align first column (Subject Name) to left for readability */
        .marks-table td:first-child, .marks-table th:first-child {
            text-align: left;
            padding-left: 1.5rem; /* Add some padding */
            white-space: normal; /* Allow subject names to wrap if long */
        }
         .marks-table td:nth-child(2), .marks-table th:nth-child(2) { /* Subject Code */
             white-space: nowrap;
         }


        .total-mark {
            font-weight: 600;
        }

        .eligible {
            color: var(--success-color);
            font-weight: 600;
        }

        .ineligible {
            color: var(--danger-color);
            font-weight: 600;
        }

        .subject-type {
            display: inline-block;
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            white-space: nowrap;
        }

        .theory {
            background-color: #e3f2fd;
            color: #1565c0;
        }

        .lab {
            background-color: #e8f5e9;
            color: #2e7d32;
        }

        .ipcc {
            background-color: #fff3e0;
            color: #e65100;
        }

        .no-marks {
            text-align: center;
            padding: 2rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-top: 2rem;
        }

        @media (max-width: 992px) { /* Adjust breakpoint if needed */
             .marks-table {
                 min-width: 0; /* Allow table to shrink */
             }
        }


        @media (max-width: 768px) {
            .container {
                padding: 4rem 1rem 1rem 1rem; /* Adjust padding */
            }

             .logout-button {
                 top: 0.5rem;
                 right: 0.5rem;
                 padding: 0.5rem 1rem;
                 font-size: 0.8rem;
             }

            /* Removed the block display for table, rely on overflow-x on container now */
            /* .marks-table {
                display: block;
                overflow-x: auto;
            } */

            .student-details {
                grid-template-columns: 1fr;
            }

            .marks-table th, .marks-table td {
                padding: 0.6rem; /* Reduce padding on smaller screens */
                font-size: 0.9rem;
            }
             .marks-table td:first-child, .marks-table th:first-child {
                 padding-left: 1rem;
             }
        }
    </style>
</head>
<body>

    <!-- <<< --- ADDED LOGOUT BUTTON HTML --- >>> -->
    <a href="logout.php" class="logout-button">Logout</a>
    <!-- <<< --- END OF ADDED HTML --- >>> -->


    <div class="container">
        <div class="header">
            <h1>My Academic Record</h1>
            <p>View your marks and academic progress</p>
        </div>


        <?php if (isset($error)): ?>
            <div class="error-message">
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php elseif ($studentData): ?>
            <div class="student-card">
                <div class="student-info">
                    <h2><?= htmlspecialchars($studentData['name']) ?></h2>
                    <div class="student-details">
                        <div class="detail-item">
                            <strong>USN</strong>
                            <span><?= htmlspecialchars($studentData['usn']) ?></span>
                        </div>
                        <div class="detail-item">
                            <strong>Department</strong>
                            <span><?= htmlspecialchars($studentData['department']) ?></span>
                        </div>
                        <div class="detail-item">
                            <strong>Section</strong>
                            <span><?= htmlspecialchars($studentData['section']) ?></span>
                        </div>
                        <div class="detail-item">
                            <strong>Semester</strong>
                            <span><?= htmlspecialchars($studentData['semester']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($marksData)): ?>
                <!-- Wrapped table in a div for better overflow control -->
                <div class="marks-table-container">
                    <table class="marks-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Code</th>
                                <th>Type</th>
                                <th>IA1</th>
                                <th>IA2</th>
                                <th>Assignment</th>
                                <th>Record1</th>
                                <th>Lab IA1</th>
                                <th>Record2</th>
                                <th>Lab IA2</th>
                                <th>Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($marksData as $row):
                                // Ensure values are treated as numbers for calculations
                                $ia1 = $row['ia1'];
                                $ia2 = $row['ia2'];
                                $assignment = $row['assignment'];
                                $record1 = $row['lab_record1'];
                                $lab_ia1 = $row['lab_ia1'];
                                $record2 = $row['lab_record2'];
                                $lab_ia2 = $row['lab_ia2'];
                                $type = $row['type'];

                                $total = 0; // Initialize total
                                $eligibility = 'NA'; // Default eligibility

                                // Determine which marks are relevant based on type
                                $show_ia1 = showMark($ia1);
                                $show_ia2 = showMark($ia2);
                                $show_assignment = showMark($assignment);
                                $show_record1 = showMark($record1);
                                $show_lab_ia1 = showMark($lab_ia1);
                                $show_record2 = showMark($record2);
                                $show_lab_ia2 = showMark($lab_ia2);

                                // Calculate total based on subject type, handle potential nulls
                                if ($type == 'Theory') {
                                    if ($ia1 !== null && $ia2 !== null) {
                                         $avg_ia = ($ia1 + $ia2) / 2;
                                         $total = round($avg_ia + (float)$assignment); // Cast assignment just in case
                                         $eligibility = ($total >= 18) ? 'Eligible' : 'Ineligible'; // Adjusted eligibility threshold to 20 (common for 50 marks CIE)
                                    } else {
                                        $eligibility = 'Pending IA';
                                    }
                                    // Hide lab-related columns
                                    $show_record1 = $show_lab_ia1 = $show_record2 = $show_lab_ia2 = 'NA';
                                } elseif ($type == 'IPCC') {
                                     if ($ia1 !== null && $ia2 !== null && $assignment !== null && $record1 !== null && $lab_ia1 !== null) {
                                        $scaled_ia = (($ia1 + $ia2) / 2) * 0.6; // 60% of IA avg
                                        $total = round($scaled_ia + (float)$assignment + (float)$record1 + (float)$lab_ia1); // Assignment + Record1 + Lab IA1
                                        $eligibility = ($total >= 18) ? 'Eligible' : 'Ineligible'; // Adjusted eligibility threshold
                                     } else {
                                         $eligibility = 'Pending Marks';
                                     }
                                    // Hide unused columns
                                    $show_record2 = $show_lab_ia2 = 'NA';
                                } elseif ($type == 'Lab') {
                                    if ($record1 !== null && $lab_ia1 !== null && $record2 !== null && $lab_ia2 !== null) {
                                        $total = (float)$record1 + (float)$lab_ia1 + (float)$record2 + (float)$lab_ia2;
                                        $eligibility = ($total >= 18) ? 'Eligible' : 'Ineligible'; // Adjusted eligibility threshold
                                    } else {
                                         $eligibility = 'Pending Marks';
                                    }
                                    // Hide theory-related columns
                                    $show_ia1 = $show_ia2 = $show_assignment = 'NA';
                                }

                                $typeClass = strtolower(str_replace(' ', '', $type)); // e.g., 'theory', 'lab', 'ipcc'
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($row['subject_name']) ?></td>
                                <td><?= htmlspecialchars($row['code']) ?></td>
                                <td><span class="subject-type <?= $typeClass ?>"><?= htmlspecialchars($type) ?></span></td>
                                <td><?= $show_ia1 ?></td>
                                <td><?= $show_ia2 ?></td>
                                <td><?= $show_assignment ?></td>
                                <td><?= $show_record1 ?></td>
                                <td><?= $show_lab_ia1 ?></td>
                                <td><?= $show_record2 ?></td>
                                <td><?= $show_lab_ia2 ?></td>
                                <td class="total-mark"><?= ($total > 0 || ($type != 'NA' && $eligibility != 'Pending IA' && $eligibility != 'Pending Marks')) ? $total : 'NA' ?></td>
                                <td class="<?= strtolower(str_replace(' ', '-', $eligibility)) // e.g., pending-ia, pending-marks ?>"><?= $eligibility ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                 </div> <!-- End of marks-table-container -->
            <?php else: ?>
                 <div class="no-marks">
                    <p>No marks have been recorded for you yet for this semester.</p>
                    <p>Please check back later or contact your faculty if you believe this is an error.</p>
                 </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</body>
</html>