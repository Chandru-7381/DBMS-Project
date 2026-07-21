<?php
require 'includes/db.php'; // Make sure this path is correct

// Variables to hold output message and its type
$message = '';
$message_type = ''; // e.g., 'success', 'error', 'warning'
$show_form = false; // Flag to control form display
$page_title = "Reset Password"; // Default title
$form_token = ''; // To pass token to the form action if needed

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    $form_token = htmlspecialchars($token); // For use in form action/hidden input

    $stmt = $conn->prepare("SELECT email, token_expiry FROM users WHERE password_reset_token = ?");

    if ($stmt === false) {
        error_log("Prepare failed (select): (" . $conn->errno . ") " . $conn->error);
        $message = "❌ An internal error occurred checking the token. Please try again later.";
        $message_type = 'error';
    } else {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows == 1) {
            $stmt->bind_result($email, $expiry);
            $stmt->fetch();

            if (strtotime($expiry) > time()) {
                // Token is valid and not expired
                $show_form = true; // Allow form to be displayed
                $page_title = "Enter New Password";

                if (isset($_POST['reset'])) {
                    // Check if password is provided and meets minimum requirements (optional but recommended)
                    if (empty($_POST['password'])) {
                        $message = "⚠ Please enter a new password.";
                        $message_type = 'warning';
                        // Keep $show_form = true to allow user to retry
                    } elseif (strlen($_POST['password']) < 8) { // Example: Minimum length check
                         $message = "⚠ Password must be at least 8 characters long.";
                         $message_type = 'warning';
                         // Keep $show_form = true
                    } else {
                        $new_password = password_hash($_POST['password'], PASSWORD_DEFAULT);

                        $stmt2 = $conn->prepare("UPDATE users SET password = ?, password_reset_token = NULL, token_expiry = NULL WHERE email = ?");
                        if ($stmt2 === false) {
                             error_log("Prepare failed (update): (" . $conn->errno . ") " . $conn->error);
                             $message = "❌ Error preparing password update. Please try again.";
                             $message_type = 'error';
                             $show_form = false; // Hide form after failed DB prepare
                        } else {
                            $stmt2->bind_param("ss", $new_password, $email);
                            if ($stmt2->execute()) {
                                $message = "✅ Your password has been successfully reset. You can now <a href='login.php'>Login</a>.";
                                $message_type = 'success';
                                $show_form = false; // Hide form after successful reset
                            } else {
                                 error_log("Execute failed (update): (" . $stmt2->errno . ") " . $stmt2->error);
                                $message = "❌ There was an error resetting your password. Please try again.";
                                $message_type = 'error';
                                $show_form = false; // Hide form after failed DB execution
                            }
                            $stmt2->close();
                        }
                    }
                }
            } else {
                // Token expired
                $message = "⛔ This password reset link has expired. Please request a new one.";
                $message_type = 'error';
                 $page_title = "Link Expired";
            }
        } else {
            // Token not found
            $message = "⛔ Invalid password reset link. Please check the link or request a new one.";
            $message_type = 'error';
             $page_title = "Invalid Link";
        }
        $stmt->close();
    }
     // $conn->close(); // Close connection if appropriate
} else {
    // No token provided in URL
    $message = "⚠ No reset token provided. Please use the link sent to your email.";
    $message_type = 'warning';
     $page_title = "Token Missing";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - CIE Marks Portal</title> <!-- Updated Title -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Your CSS remains the same */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            height: 100%;
        }

        body {
            font-family: 'Poppins', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            /* Vibrant gradient - adjust colors as needed */
            background: linear-gradient(135deg, #ff758c 0%, #ff7eb3 100%);
            color: #333;
            line-height: 1.6;
            padding: 20px;
        }

        .reset-container {
            background-color: #ffffff;
            padding: 40px 50px;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 450px;
            text-align: center;
            animation: popIn 0.5s cubic-bezier(0.68, -0.55, 0.27, 1.55) forwards;
            opacity: 0;
            transform: scale(0.8);
        }

        @keyframes popIn {
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .reset-container h2 { /* Use H2 for main title */
            margin-bottom: 25px;
            color: #4a4a4a;
            font-weight: 700;
            font-size: 1.8em;
        }

        .input-group {
            margin-bottom: 25px;
            text-align: left; /* Align labels/placeholders left */
        }

        .input-group label { /* Optional: Good for accessibility */
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #555;
            font-size: 0.9em;
        }

        .input-group input[type="password"] {
            width: 100%;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .input-group input[type="password"]:focus {
            border-color: #ff758c; /* Use theme color */
            outline: none;
            box-shadow: 0 0 8px rgba(255, 117, 140, 0.3); /* Subtle glow */
        }

        .input-group input::placeholder {
            color: #aaa;
            font-style: italic;
        }

        button[type="submit"] {
            width: 100%;
            padding: 15px;
            /* Button Gradient */
            background: linear-gradient(135deg, #f78ca0 0%, #f9748f 100%);
            border: none;
            border-radius: 8px;
            color: #ffffff;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        button[type="submit"]:hover {
            background: linear-gradient(135deg, #f57a90 0%, #f7627f 100%); /* Slightly darker/more saturated */
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18);
        }

         button[type="submit"]:active {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
         }

        /* Message Styling */
        .message {
            padding: 15px 20px; /* More padding */
            margin-bottom: 25px; /* Space below message */
            border-radius: 8px;
            text-align: center;
            font-size: 1em; /* Slightly larger message font */
            border: 1px solid transparent;
            animation: fadeInSlideDown 0.5s ease-out forwards;
            opacity: 0;
        }

        @keyframes fadeInSlideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message.success {
            background-color: #e0f5e9; /* Softer green */
            color: #1d7447;
            border-color: #c3e6cb;
        }
        .message.success a {
            color: #145230; /* Darker green link */
            font-weight: 600;
            text-decoration: underline;
        }
        .message.success a:hover {
            color: #0d361f;
        }

        .message.error {
            background-color: #fde2e5; /* Softer red */
            color: #9a2233;
            border-color: #f5c6cb;
        }

        .message.warning {
            background-color: #fff8e1; /* Softer yellow */
            color: #8d710b;
            border-color: #ffeeba;
        }

        /* Link back to login/forgot password for errors */
        .action-links {
             margin-top: 25px;
             font-size: 0.9em;
         }

        .action-links a {
             color: #ff758c; /* Theme color */
             text-decoration: none;
             font-weight: 600;
             margin: 0 10px; /* Space out links */
             transition: color 0.3s ease;
         }

        .action-links a:hover {
             color: #f9748f; /* Other theme color */
             text-decoration: underline;
         }
    </style>
</head>
<body>

    <div class="reset-container">
        <h2><?php echo htmlspecialchars($page_title); ?></h2>

        <?php
        // Display the main message if it's set
        if (!empty($message) && !$show_form) { 
             echo "<div class='message " . htmlspecialchars($message_type) . "'>" . $message . "</div>"; 

             if ($message_type == 'error' || $message_type == 'warning') {
                 echo "<div class='action-links'>";
                 echo "<a href='forgot_password.php'>Request New Link</a>";
                 echo "<a href='login.php'>Back to Login</a>";
                 echo "</div>";
             }

        } elseif (!empty($message) && $show_form && $message_type == 'warning') {
              echo "<div class='message " . htmlspecialchars($message_type) . "'>" . htmlspecialchars($message) . "</div>";
        }
        ?>

        <?php if ($show_form): ?>
        <form method="POST" action="reset_password.php?token=<?php echo $form_token; ?>">
            <div class="input-group">
                <label for="password">New Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your new password (min 8 chars)" required>
            </div>
            <button type="submit" name="reset">Reset Password</button>
        </form>
        <?php endif; ?>

    </div>

</body>
</html>