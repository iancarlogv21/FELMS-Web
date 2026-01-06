<?php
session_start();
require_once __DIR__ . '/config/db.php';

$token = $_GET['token'] ?? null;
$error = null;
$user = null;

if (!$token) {
    die("No token provided.");
}

// The token from the URL is plain text. We need to hash it to find it in the DB.
$token_hash = hash('sha256', $token);

try {
    $db = Database::getInstance();
    $adminsCollection = $db->admins();
    
    // Find user by the token hash and check if it's expired
    $user = $adminsCollection->findOne([
        'reset_token_hash' => $token_hash,
        'reset_token_expires_at' => ['$gt' => new MongoDB\BSON\UTCDateTime()]
    ]);
    
    if (!$user) {
        // Token is invalid or has expired
        die("This password reset link is invalid or has expired. Please request a new one.");
    }
    
} catch (Exception $e) {
    die("Database error. Cannot verify reset token.");
}


// Handle the form submission for the new password
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];

    if (empty($password) || strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($password !== $password_confirm) {
        $error = "Passwords do not match.";
    } else {
        // Validation passed, update the password
        $new_password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        $adminsCollection->updateOne(
            ['_id' => $user['_id']],
            [
                '$set' => ['password' => $new_password_hash],
                '$unset' => ['reset_token_hash' => "", 'reset_token_expires_at' => ""]
            ]
        );
        
        $_SESSION['success_message'] = "Your password has been reset successfully! You can now log in.";
        header("Location: login.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - FELMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md p-8 space-y-6 bg-white rounded-lg shadow-md">
        <h2 class="text-2xl font-bold text-center text-gray-900">Create a New Password</h2>
        
        <?php if ($error): ?>
            <div class="p-4 text-sm text-red-700 bg-red-100 rounded-lg" role="alert">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>">
            <div>
                <label for="password" class="text-sm font-medium text-gray-700">New Password</label>
                <input type="password" name="password" id="password" required class="w-full px-3 py-2 mt-1 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500" placeholder="Minimum 8 characters">
            </div>
             <div>
                <label for="password_confirm" class="text-sm font-medium text-gray-700">Confirm New Password</label>
                <input type="password" name="password_confirm" id="password_confirm" required class="w-full px-3 py-2 mt-1 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500">
            </div>
            <div>
                <button type="submit" class="w-full px-4 py-2 font-semibold text-white bg-orange-600 rounded-md hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                    Set New Password
                </button>
            </div>
        </form>
    </div>
</body>
</html>