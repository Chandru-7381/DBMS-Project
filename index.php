<?php
session_start();

// If already logged in, go to dashboard
if (isset($_SESSION['student_id'])) {
    header("Location: dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Marks Portal</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="form-container" style="text-align: center;">
        <h2>Welcome to Student Marks Portal</h2>
        <p>Login or Signup to continue</p>
        <a href="login.php"><button>Login</button></a>
        <a href="signup.php"><button>Sign Up</button></a>
    </div>
</body>
</html>
