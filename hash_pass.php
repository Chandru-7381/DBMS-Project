<?php
$plainPassword = 'Admin@123'; // Put your chosen password here
$hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
echo "Password: " . $plainPassword . "<br>";
echo "Hashed: " . $hashedPassword;
?>