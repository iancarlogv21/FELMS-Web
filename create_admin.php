<?php
require_once __DIR__ . '/config/db.php';

$message = null;
$message_type = 'info';

// Only process the form if it has been submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $plainPassword = $_POST['password'] ?? '';

    // --- Input Validation ---
    if (empty($username) || empty($email) || empty($fullName) || empty($plainPassword)) {
        $message = "All fields are required.";
        $message_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $message_type = 'error';
    } elseif (strlen($plainPassword) < 8) {
        $message = "Password must be at least 8 characters long.";
        $message_type = 'error';
    } else {
        try {
            $db = Database::getInstance();
            $adminsCollection = $db->admins();

            // Check if username or email already exists
            $existingUser = $adminsCollection->findOne([
                '$or' => [
                    ['username' => $username],
                    ['email' => $email]
                ]
            ]);

            if ($existingUser) {
                $message = "A user with this username or email already exists.";
                $message_type = 'error';
            } else {
                // All checks passed, create the new user
                $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

                $adminsCollection->insertOne([
                    'username'      => $username,
                    'password'      => $hashedPassword,
                    'email'         => $email,
                    'full_name'     => $fullName,
                    'profile_image' => null, // Set a default null profile image
                    'created_at'    => new MongoDB\BSON\UTCDateTime()
                ]);

                $message = "Successfully created admin user '{$username}'. You can now log in.";
                $message_type = 'success';
            }
        } catch (Exception $e) {
            $message = "An error occurred: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin User - FELMS Setup</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="w-full max-w-lg p-8 space-y-6 bg-white rounded-lg shadow-md">
        <h1 class="text-3xl font-bold text-center text-gray-900">Create Admin User</h1>
        
        > ⚠️ **Important Security Note:** This script should be deleted or protected after you have created your initial user(s). Leaving it on a live server is a security risk.

        <?php if ($message): ?>
            <div class="p-4 mt-4 rounded-md text-sm <?php echo $message_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="create_admin.php" class="space-y-4">
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username" id="username" required class="w-full px-3 py-2 mt-1 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500">
            </div>
             <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                <input type="email" name="email" id="email" required class="w-full px-3 py-2 mt-1 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500">
            </div>
            <div>
                <label for="full_name" class="block text-sm font-medium text-gray-700">Full Name</label>
                <input type="text" name="full_name" id="full_name" required class="w-full px-3 py-2 mt-1 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" id="password" required class="w-full px-3 py-2 mt-1 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500" placeholder="Minimum 8 characters">
            </div>
            <div>
                <button type="submit" class="w-full px-4 py-2 font-semibold text-white bg-orange-600 rounded-md hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                    Create Admin
                </button>
            </div>
        </form>
    </div>
</body>
</html>