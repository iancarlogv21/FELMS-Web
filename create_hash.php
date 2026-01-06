<?php
// We are creating a hash for the password 'precious'
$passwordToHash = 'precious';

$hashedPassword = password_hash($passwordToHash, PASSWORD_DEFAULT);

echo "<h3>Your Secure Password Hash:</h3>";
echo "<p>Copy this entire string below and paste it into the password field in your database.</p>";
echo "<textarea rows='4' cols='80' readonly>" . htmlspecialchars($hashedPassword) . "</textarea>";
?>