<?php
// ** logout.php **

// Always start the session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Unset all session variables
$_SESSION = array();

// 2. Destroy the session cookie
// Note: This will destroy the session, and not just the session data!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, // Set expiry in the past
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Finally, destroy the session on the server
session_destroy();

// 4. Redirect the user to the login page (or homepage)
//    IMPORTANT: Change 'login.php' to the actual path of your login page
//               or any other page you want users to land on after logout (e.g., 'index.php').
header("Location: login.php"); // <--- CHANGE THIS IF NEEDED

// 5. Ensure no further code is executed after redirection
exit;
?>