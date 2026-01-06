<?php
session_start();
require_once __DIR__ . '/config/db.php';

$message = null;
$message_type = 'info';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['email'])) {
    $email = trim($_POST['email']);
    
    try {
        $db = Database::getInstance();
        $adminsCollection = $db->admins();
        
        $user = $adminsCollection->findOne(['email' => $email]);
        
        if ($user) {
            // User found, generate a secure token
            $token = bin2hex(random_bytes(32));
            $token_hash = hash('sha256', $token);
            
            // Set an expiration time (e.g., 1 hour from now)
            $expires_at = new MongoDB\BSON\UTCDateTime((time() + 3600) * 1000);
            
            // Store the token hash and expiry in the user's document
            $adminsCollection->updateOne(
                ['_id' => $user['_id']],
                ['$set' => [
                    'reset_token_hash' => $token_hash,
                    'reset_token_expires_at' => $expires_at
                ]]
            );
            
            // --- Email Sending Simulation ---
            // In a real application, you would use a library like PHPMailer to send an email.
            // For development, we'll display the link directly.
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
            
            $message = "<strong>DEVELOPMENT MODE:</strong> A real email would be sent. Click this link to reset your password:<br><a href='{$reset_link}' class='text-blue-600 hover:underline'>{$reset_link}</a>";
            $message_type = 'success';

        } else {
            // To prevent user enumeration, show the same message even if the email doesn't exist.
            $message = "If an account with that email address exists, a password reset link has been sent.";
            $message_type = 'success';
        }
        
    } catch (Exception $e) {
        $message = "A database error occurred. Please try again later.";
        $message_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - FELMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md p-8 space-y-6 bg-white rounded-lg shadow-md">
        <h2 class="text-2xl font-bold text-center text-gray-900">Forgot Your Password?</h2>
        <p class="text-center text-gray-600">Enter the email address associated with your account, and we'll send you a link to reset your password.</p>
        
        <?php if ($message): ?>
            <div class="p-4 rounded-md <?php echo $message_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="forgot_password.php">
            <div>
                <label for="email" class="text-sm font-medium text-gray-700">Email Address</label>
                <input type="email" name="email" id="email" required class="w-full px-3 py-2 mt-1 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500">
            </div>
            <div>
                <button type="submit" class="w-full px-4 py-2 font-semibold text-white bg-orange-600 rounded-md hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                    Send Reset Link
                </button>
            </div>
        </form>
        <div class="text-center">
            <a href="login.php" class="text-sm text-orange-600 hover:underline">Back to Login</a>
        </div>
    </div>
</body>
</html>