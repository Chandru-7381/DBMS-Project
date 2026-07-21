<?php
require 'includes/db.php'; // Make sure this path is correct
require 'vendor/autoload.php'; // Make sure this path is correct

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- START OF MODIFICATION ---
// Define your live application's base URL
// IMPORTANT: Adjust this if your project is NOT in a 'htdocs' subfolder on cie.alchosting.xyz
// If your project is in the root of cie.alchosting.xyz, set this to: 'http://cie.alchosting.xyz'
define('BASE_APP_URL', 'http://cie.alchosting.xyz');
// --- END OF MODIFICATION ---

// Variable to hold the message to be displayed
$message = '';
$message_type = ''; // 'success', 'error', 'warning'

if (isset($_POST['submit'])) {
    $email = $_POST['email'];

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "⚠ Please enter a valid email address.";
        $message_type = 'warning';
    } else {
        // Check if email exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        if ($stmt === false) {
            error_log("Prepare failed (select): (" . $conn->errno . ") " . $conn->error);
            $message = "❌ An internal error occurred. Please try again later.";
            $message_type = 'error';
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows == 1) {
                $token = bin2hex(random_bytes(32));
                $expiry = date("Y-m-d H:i:s", strtotime("+15 minutes")); // 15 minutes expiry

                // Update token and expiry in DB
                $stmt2 = $conn->prepare("UPDATE users SET password_reset_token = ?, token_expiry = ? WHERE email = ?");
                 if ($stmt2 === false) {
                    error_log("Prepare failed (update): (" . $conn->errno . ") " . $conn->error);
                    $message = "❌ Failed to generate reset token. Please try again.";
                    $message_type = 'error';
                 } else {
                    $stmt2->bind_param("sss", $token, $expiry, $email);

                    if ($stmt2->execute()) {
                        // Send reset link using PHPMailer
                        $mail = new PHPMailer(true); // Enable exceptions
                        try {
                            // Server settings - Make sure these are correct
                            // IMPORTANT SECURITY NOTE: Consider moving sensitive credentials (username, password)
                            // to a configuration file outside your web root or use environment variables.
                            $mail->isSMTP();
                            $mail->Host = 'smtp.gmail.com'; // Your SMTP Host (e.g., smtp.gmail.com)
                            $mail->SMTPAuth = true;
                            $mail->Username = 'chandrus2775@gmail.com'; // 🔁 Your Email Address
                            $mail->Password = 'klgo yjbk pucr aqwa'; // 🔐 Your App Password (NOT your email login password)
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Use 'tls' or 'ssl'
                            $mail->Port = 587; // Port for TLS (587) or SSL (465)

                            // Recipients
                            $mail->setFrom('chandrus2775@gmail.com', 'cie_marks'); // From Email and Name
                            $mail->addAddress($email); // Add a recipient

                            // Content
                            $mail->isHTML(false); // Set email format to plain text
                            $mail->Subject = "Password Reset Request";

                            // --- START OF MODIFICATION ---
                            // Use the defined BASE_APP_URL for the reset link
                            $resetLink = BASE_APP_URL . "/reset_password.php?token=" . urlencode($token);
                            // --- END OF MODIFICATION ---

                            $mail->Body = "Hello,\n\nYou requested a password reset. Click the link below to reset your password. This link is valid for 15 minutes:\n\n" .
                                $resetLink . "\n\nIf you did not request this, please ignore this email.\n\nRegards,\nCIE Marks Team"; // Updated team name

                            $mail->send();
                            $message = "✅ Password reset link has been sent to your email. If you don't see it, please check your spam/junk folder.";
                            $message_type = 'success';
                        } catch (Exception $e) {
                            error_log("PHPMailer Error: " . $mail->ErrorInfo); // Log the detailed error
                            $message = "❌ Message could not be sent. Please try again later or contact support. Error: " . $mail->ErrorInfo; // Show detailed error for now
                            $message_type = 'error';
                        }
                    } else {
                         error_log("Execute failed (update): (" . $stmt2->errno . ") " . $stmt2->error);
                         $message = "❌ Failed to update reset token. Please try again.";
                         $message_type = 'error';
                    }
                    $stmt2->close();
                }
            } else {
                // Email not found - show a generic message for security (don't reveal which emails exist)
                $message = "✅ If an account with that email exists, a password reset link has been sent. Please check your inbox and spam/junk folder.";
                 $message_type = 'success'; // Still show success to prevent email enumeration
            }
            $stmt->close();
        }
         // $conn->close(); // Consider connection management based on your application structure
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - CIE Marks Portal</title> <!-- Updated Title -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Your CSS remains the same, no changes needed here */
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
            background: linear-gradient(135deg, #7f53ac 0%, #647dee 100%); /* Different vibrant gradient */
            color: #333;
            line-height: 1.6;
            padding: 20px;
        }

        .forgot-password-container {
            background-color: #ffffff;
            padding: 40px 50px;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 450px;
            text-align: center;
            animation: fadeInScale 0.6s ease-out forwards;
            opacity: 0; /* Start hidden for animation */
            transform: scale(0.95); /* Start slightly scaled down */
        }

        @keyframes fadeInScale {
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .forgot-password-container h2 { /* Changed from h3 for better hierarchy */
            margin-bottom: 15px;
            color: #4a4a4a;
            font-weight: 700;
            font-size: 1.8em;
        }

        .forgot-password-container p.instructions {
            margin-bottom: 30px;
            color: #666;
            font-size: 0.95em;
        }

        .input-group {
            margin-bottom: 25px;
            text-align: left;
        }

        .input-group label { /* Optional Label */
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
            font-size: 0.9em;
        }

        .input-group input[type="email"] {
            width: 100%;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .input-group input[type="email"]:focus {
            border-color: #7f53ac; /* Use theme color */
            outline: none;
            box-shadow: 0 0 8px rgba(127, 83, 172, 0.3);
        }

        .input-group input::placeholder {
            color: #aaa;
            font-style: italic;
        }

        button[type="submit"] {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%); /* Another vibrant gradient */
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
            background: linear-gradient(135deg, #5e0dad 0%, #1e63d1 100%);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        }

         button[type="submit"]:active {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
         }

        /* Message Styling */
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            text-align: center;
            font-size: 0.95em;
            border: 1px solid transparent;
            animation: slideDown 0.4s ease-out forwards;
            opacity: 0;
        }

        @keyframes slideDown {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        .message.warning {
            background-color: #fff3cd;
            color: #856404;
            border-color: #ffeeba;
        }

        .login-link {
             margin-top: 25px;
             font-size: 0.9em;
         }

        .login-link a {
             color: #647dee; /* Link color from gradient */
             text-decoration: none;
             font-weight: 600;
             transition: color 0.3s ease;
         }

        .login-link a:hover {
             color: #7f53ac; /* Other link color from gradient */
             text-decoration: underline;
         }

    </style>
</head>
<body>

    <div class="forgot-password-container">
        <h2>Forgot Your Password?</h2>
        <p class="instructions">No problem. Enter your email address below and we'll send you a link to reset it.</p>

        <?php
        // Display the message if it's set
        if (!empty($message)) {
            echo "<div class='message " . htmlspecialchars($message_type) . "'>" . htmlspecialchars($message) . "</div>";
        }
        ?>

        <form method="POST" action="forgot_password.php">
            <div class="input-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="e.g., yourname@example.com" required
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
            <button type="submit" name="submit">Send Reset Link</button>
        </form>

        <p class="login-link">
            Remembered your password? <a href="login.php">Login Here</a>
        </p>
    </div>

</body>
</html>