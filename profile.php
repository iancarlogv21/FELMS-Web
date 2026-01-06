<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// --- HELPER FUNCTIONS FOR PROFILE MANAGEMENT ---

function handleProfileUpdate(Database $db, string $username, array $post, array $files): void {
    $updateData = [];

    if (!empty($post['full_name'])) {
        $updateData['full_name'] = trim($post['full_name']);
    }
    if (!empty($post['email']) && filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
        $updateData['email'] = trim($post['email']);
    } elseif (!empty($post['email'])) {
        $_SESSION['error_message'] = "Invalid email format provided.";
        return;
    }

    if (isset($files["profile_image"]) && $files["profile_image"]["error"] == 0) {
        $newImagePath = handleImageUpload($db, $username, $files["profile_image"]);
        if ($newImagePath) {
            $updateData['profile_image'] = $newImagePath;
        } else {
            return;
        }
    }

    if (!empty($updateData)) {
        $db->admins()->updateOne(['username' => $username], ['$set' => $updateData]);
        $_SESSION['success_message'] = "Profile information updated successfully!";

        // THE FIX: Update session variables after a successful database update.
        if (isset($updateData['full_name'])) {
            $_SESSION['full_name'] = $updateData['full_name'];
        }
        if (isset($updateData['email'])) {
            $_SESSION['email'] = $updateData['email'];
        }
        if (isset($updateData['profile_image'])) {
            $_SESSION['profile_image'] = $updateData['profile_image'];
        }
    }
}

function handleImageUpload(Database $db, string $username, array $file): ?string {
    $allowed = ["jpg" => "image/jpeg", "png" => "image/png"];
    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));

    if (!array_key_exists($ext, $allowed)) {
        $_SESSION['error_message'] = "Invalid file format. Please upload a JPG or PNG.";
    } elseif ($file["size"] > 5 * 1024 * 1024) {
        $_SESSION['error_message'] = "File size is too large (Max 5MB).";
    } else {
        $currentUser = $db->admins()->findOne(['username' => $username]);
        $oldImagePath = $currentUser['profile_image'] ?? null;
        
        $uploadDir = "uploads/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $newFilename = "profile_{$username}_" . uniqid() . ".{$ext}";
        $newFilepath = $uploadDir . $newFilename;

        if (move_uploaded_file($file["tmp_name"], $newFilepath)) {
            if ($oldImagePath && file_exists($oldImagePath)) {
                @unlink($oldImagePath);
            }
            return $newFilepath;
        } else {
            $_SESSION['error_message'] = "There was an error moving the uploaded file.";
        }
    }
    return null;
}

function handlePasswordChange(Database $db, string $username, array $post): void {
    $oldPassword = $post['old_password'] ?? '';
    $newPassword = $post['new_password'] ?? '';
    $confirmPassword = $post['confirm_password'] ?? '';

    if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) {
        $_SESSION['error_message'] = "All password fields are required.";
        return;
    }
    if ($newPassword !== $confirmPassword) {
        $_SESSION['error_message'] = "New passwords do not match.";
        return;
    }
    if (strlen($newPassword) < 8) {
        $_SESSION['error_message'] = "New password must be at least 8 characters long.";
        return;
    }

    $user = $db->admins()->findOne(['username' => $username]);
    if ($user && password_verify($oldPassword, $user['password'])) {
        $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $db->admins()->updateOne(
            ['username' => $username],
            ['$set' => ['password' => $newHashedPassword]]
        );
        $_SESSION['success_message'] = "Password changed successfully!";
    } else {
        $_SESSION['error_message'] = "Incorrect old password.";
    }
}

// --- MAIN PAGE LOGIC ---
$db_error = null;
try {
    $dbInstance = Database::getInstance();

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_profile') {
            handleProfileUpdate($dbInstance, $_SESSION['username'], $_POST, $_FILES);
        } elseif ($action === 'change_password') {
            handlePasswordChange($dbInstance, $_SESSION['username'], $_POST);
        }
        header("location: profile.php");
        exit;
    }

    // Fetch user data on load
    $user = $dbInstance->admins()->findOne(['username' => $_SESSION['username']]);
    if (!$user) {
        die("Error: Could not find user data for the current session.");
    }
} catch (Exception $e) {
    $db_error = "Database Error: " . $e->getMessage();
    // Fallback user data
    $user = ['username' => $_SESSION['username'] ?? 'Error', 'full_name' => 'N/A', 'email' => 'N/A', 'role' => 'Administrator'];
}

// Set $currentPage for sidebar to highlight the correct item (assuming 'dashboard' or 'settings' is used)
$currentPage = 'settings'; 
$pageTitle = 'My Profile - FELMS';
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/sidebar.php';
?>
<main id="main-content" class="flex-1 p-6 md:p-10">
    <header class="mb-8">
        <h1 class="text-4xl font-bold tracking-tight text-text">My Profile</h1>
    </header>

    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-800 p-4 mb-6 rounded-r-lg" role="alert"><p><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></p></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-6 rounded-r-lg" role="alert"><p><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></p></div>
    <?php endif; ?>
    <?php if ($db_error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-6 rounded-r-lg" role="alert"><p><?php echo htmlspecialchars($db_error); ?></p></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 space-y-8">
            <form action="profile.php" method="POST" enctype="multipart/form-data" class="bg-card p-8 rounded-2xl border border-theme shadow-sm">
                <input type="hidden" name="action" value="update_profile">
                <h3 class="text-2xl font-bold mb-6 border-b border-theme pb-4 text-text">Edit Information</h3>
                
                <div class="space-y-6">
                    <div class="pb-4">
                        <label class="block text-sm font-medium text-secondary mb-2">Profile Picture</label>
                        <div class="flex items-center gap-4">
                            <img id="photo_preview" src="<?php echo htmlspecialchars($user['profile_image'] ?? 'https://placehold.co/128x128/ccc/666?text=AD'); ?>" class="w-20 h-20 rounded-full object-cover shadow-md border-2 border-theme">
                            
                            <input type="file" name="profile_image" id="profile_image" class="hidden" accept=".jpg,.jpeg,.png" onchange="document.getElementById('photo_preview').src = window.URL.createObjectURL(this.files[0])">
                            <label for="profile_image" class="px-4 py-2 font-semibold text-white bg-gray-600 rounded-lg shadow-md hover:bg-gray-700 cursor-pointer transition-all">
                                Choose Photo
                            </label>
                        </div>
                    </div>
                    
                    <div class="pt-2">
                        <label for="full_name" class="form-label text-secondary">Full Name</label>
                        <input type="text" id="full_name" name="full_name" class="form-input" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" placeholder="Enter full name">
                    </div>
                    
                    <div>
                        <label for="email" class="form-label text-secondary">Email Address</label>
                        <input type="email" id="email" name="email" class="form-input" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" placeholder="Enter email address">
                    </div>
                </div>
                
                <div class="border-t border-theme mt-6 pt-6 flex justify-end">
                    <button type="submit" class="flex justify-center items-center gap-2 w-full sm:w-auto px-6 py-3 font-semibold text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        <span>Save Information</span>
                    </button>
                </div>
            </form>

            <form action="profile.php" method="POST" class="bg-card p-8 rounded-2xl border border-theme shadow-sm">
                <input type="hidden" name="action" value="change_password">
                <h3 class="text-2xl font-bold mb-6 border-b border-theme pb-4 text-text">Change Password</h3>
                <div class="space-y-6">
                    <div>
                        <label for="old_password" class="form-label text-secondary">Old Password</label>
                        <input type="password" id="old_password" name="old_password" class="form-input" required>
                    </div>
                    <div>
                        <label for="new_password" class="form-label text-secondary">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-input" required placeholder="Minimum 8 characters">
                    </div>
                    <div>
                        <label for="confirm_password" class="form-label text-secondary">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-input" required>
                    </div>
                </div>
                <div class="border-t border-theme mt-6 pt-6 flex justify-end">
                    <button type="submit" class="flex justify-center items-center gap-2 w-full sm:w-auto px-6 py-3 font-semibold text-white bg-amber-600 rounded-lg shadow-md hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 transition-all">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <span>Update Password</span>
                    </button>
                </div>
            </form>
        </div>

        <div class="lg:col-span-1">
            <div class="bg-card p-8 rounded-2xl border border-theme shadow-sm">
                <h3 class="text-2xl font-bold mb-6 border-b border-theme pb-4 text-text">User Details</h3>
                <div class="space-y-4 text-text">
                    <p>
                        <span class="font-semibold text-secondary block text-sm mb-1">Username:</span>
                        <?php echo htmlspecialchars($user['username']); ?>
                    </p>
                    <p>
                        <span class="font-semibold text-secondary block text-sm mb-1">Full Name:</span>
                        <?php echo htmlspecialchars($user['full_name'] ?? 'Not Set'); ?>
                    </p>
                    <p>
                        <span class="font-semibold text-secondary block text-sm mb-1">Email:</span>
                        <?php echo htmlspecialchars($user['email'] ?? 'Not Set'); ?>
                    </p>
                    <p>
                        <span class="font-semibold text-secondary block text-sm mb-1">Role:</span>
                        Administrator
                    </p>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/templates/footer.php'; ?>