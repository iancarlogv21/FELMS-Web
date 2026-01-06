<?php
// Always start the session at the very top of the script
session_start();

// 1. INCLUDE DATABASE CONFIGURATION
require_once __DIR__ . '/config/db.php';

// If a user is already logged in, redirect them to the dashboard.
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: dashboard.php");
    exit;
}

// 2. HELPER FUNCTIONS

/**
 * Gets the true client IP address, checking for proxies.
 *
 * @return string The client's IP address.
 */
function get_client_ip(): string {
    $ip = '';
    
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }

    $ip_array = explode(',', $ip);
    $final_ip = trim($ip_array[0]);

    return ($final_ip === '::1') ? '127.0.0.1' : $final_ip;
}

/**
 * Logs a login attempt with detailed information.
 *
 * @param Database $db The custom Database class instance.
 * @param string   $username The username from the login attempt.
 * @param string   $status 'Success' or 'Failure'.
 */
function log_login_attempt(Database $db, string $username, string $status): void {
    try {
        $ip_address = get_client_ip();
        $location = 'Localhost';

        if ($ip_address !== '127.0.0.1') {
            $geo_data_json = @file_get_contents("http://ip-api.com/json/{$ip_address}");
            if ($geo_data_json) {
                $geo_data = json_decode($geo_data_json);
                if (is_object($geo_data) && isset($geo_data->status) && $geo_data->status === 'success') {
                    $location = "{$geo_data->city}, {$geo_data->country}";
                }
            }
        }

        $logEntry = [
            'username'   => $username,
            'timestamp'  => new MongoDB\BSON\UTCDateTime(),
            'status'     => $status,
            'ip_address' => $ip_address,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'location'   => $location
        ];

        $db->login_history()->insertOne($logEntry);
    } catch (Exception $e) {
        error_log("Failed to log login attempt: " . $e->getMessage());
    }
}

// 3. MAIN LOGIN PROCESSING
$error = $_SESSION['error_message'] ?? null;
unset($_SESSION['error_message']);

$success = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Username and password are required.";
    } else {
        try {
            $db_instance = Database::getInstance();
            $user = $db_instance->admins()->findOne(['username' => $username]);

            if ($user && isset($user['password']) && password_verify($password, $user['password'])) {
                // SUCCESS
                session_regenerate_id(true); // Prevent session fixation

                // Set all session variables for a synced experience
                $_SESSION['loggedin'] = true;
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'] ?? '';
                $_SESSION['email'] = $user['email'] ?? '';
                $_SESSION['profile_image'] = $user['profile_image'] ?? null;

                log_login_attempt($db_instance, $username, 'Success');
                
                header("location: dashboard.php");
                exit;
            } else {
                // FAILURE
                $error = "Invalid username or password.";
                log_login_attempt(Database::getInstance(), $username ?: 'unknown', 'Failure');
            }
        } catch (\MongoDB\Driver\Exception\Exception $e) {
            $error = "A system error occurred. Please try again later.";
            error_log("Login DB Error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Library Management System</title>
    <link rel="icon" type="image/png" href="assets/images/haha.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700&family=Lato:wght@400;700&display=swap" rel="stylesheet">
    
    <script>
        // Set theme on initial load to prevent FOUC (Flash of Unstyled Content)
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <style>
        body {
            font-family: 'Lato', sans-serif;
            transition: background-color 0.3s ease;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Merriweather', serif;
        }
        .right-panel {
            background: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.6)), url('https://images.unsplash.com/photo-1521587760476-6c12a4b040da?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1170&q=80');
            background-size: cover;
            background-position: center;
        }
        .btn-primary, .input-field, .logo-icon, .login-card {
            transition: all 0.3s ease-in-out;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }
        .logo-icon:hover {
            transform: scale(1.1);
        }
        @keyframes fade-in-down {
            0% { opacity: 0; transform: translateY(-20px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in-down {
            animation: fade-in-down 0.6s ease-out forwards;
        }
        @keyframes float-up {
            0% { transform: translateY(20px); opacity: 0; }
            50% { opacity: 0.7; }
            100% { transform: translateY(-20px); opacity: 0; }
        }
        .animated-book {
            position: absolute;
            animation: float-up 8s ease-in-out infinite;
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.5rem;
        }

        /**
         * =================================================================
         * DARK MODE STYLES
         * =================================================================
         */
        html.dark body {
            background-color: #0f172a; /* bg-slate-900 */
        }
        html.dark .login-card {
            background-color: #1e293b; /* bg-slate-800 */
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        html.dark .login-card h1,
        html.dark .login-card h2,
        html.dark .login-card p,
        html.dark .login-card label {
            color: #e2e8f0; /* text-slate-200 */
        }
        html.dark .input-field {
            background-color: #334155; /* bg-slate-700 */
            border-color: #475569;      /* border-slate-600 */
            color: #f8fafc;              /* text-slate-50 */
        }
        html.dark .input-field::placeholder {
            color: #94a3b8; /* placeholder-slate-400 */
        }
        html.dark .input-field:focus {
            --tw-ring-color: #f97316; /* focus:ring-orange-500 */
        }

        /* Dark mode for error message */
        html.dark .error-message {
            background-color: rgba(239, 68, 68, 0.1);
            border-color: #ef4444;
            color: #fca5a5;
        }
        html.dark .error-message .font-bold {
            color: #f87171;
        }


        /* === FIX: DISABLE BROWSER'S NATIVE PASSWORD ICONS === */
    input[type="password"]::-ms-reveal,
    input[type="password"]::-ms-clear,
    input[type="password"]::-webkit-contacts-auto-fill-button,
    input[type="password"]::-webkit-credentials-auto-fill-button {
        display: none !important;
        visibility: hidden !important;
        pointer-events: none !important;
        position: absolute !important; /* Forces it out of the field area */
    }
    
    /* === Ensures the custom icon sits correctly in the field === */
    .relative input[type="password"] {
        padding-right: 2.75rem !important; /* Ensure space for YOUR custom icon */
    }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="login-card flex bg-white rounded-2xl shadow-2xl overflow-hidden max-w-5xl w-full mx-4 my-8" style="height: 700px;">
        
        <div class="w-full lg:w-1/2 p-8 sm:p-12 flex flex-col justify-center animate-fade-in-down">
            <div class="mb-8">
                <div class="flex items-center justify-center lg:justify-start mb-4">
                    <svg class="h-10 w-10 text-orange-600 mr-3 logo-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                    </svg>
                    <h1 class="text-2xl font-bold text-gray-800">Fast & Efficient LMS</h1>
                </div>
                <h2 class="text-3xl font-bold text-gray-900 mb-2">Librarian Portal</h2>
                <p class="text-gray-500">Please sign in to continue.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="error-message bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md mb-6" role="alert">
                    <p class="font-bold">Access Denied</p>
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <form class="space-y-6" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input id="username" name="username" type="text" required 
                           autocomplete="nope" 
                           class="input-field block w-full px-4 py-3 border border-gray-300 rounded-lg placeholder-gray-400 text-gray-900 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent" 
                           placeholder="Enter your username">
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <div class="relative">
                        <input id="password" name="password" type="password" 
                               autocomplete="new-password" spellcheck="false" data-lpignore="true"
                               required 
                               class="input-field block w-full px-4 py-3 pr-12 border border-gray-300 rounded-lg placeholder-gray-400 text-gray-900 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent" 
                               placeholder="Enter your password">
                        
                        <button type="button" id="togglePasswordVisibility" 
                                 class="absolute top-1/2 right-3 -translate-y-1/2 p-1.5 rounded-full text-gray-400 hover:text-gray-600 active:scale-95 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-orange-300" 
                                 aria-label="Toggle password visibility">
                            <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <svg id="eye-slash-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 hidden">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.243 4.243L6.228 6.228" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-end">
                    <div class="text-sm">
                        <a href="#" class="font-medium text-orange-700 hover:text-orange-600">
                            Forgot your password?
                        </a>
                    </div>
                </div>

                <div>
                    <button type="submit" class="btn-primary group w-full flex justify-center py-3 px-4 border border-transparent text-lg font-semibold rounded-lg text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                        Sign In
                    </button>
                </div>
            </form>
        </div>

        <div class="hidden lg:flex lg:w-1/2 right-panel relative p-12 flex-col items-center justify-center text-white text-center">
            
            <div class="absolute inset-0 overflow-hidden">
                <div class="animated-book" style="left: 10%; animation-delay: 0s;">&#128214;</div>
                <div class="animated-book" style="left: 20%; animation-delay: 3s; font-size: 1.2rem;">&#128217;</div>
                <div class="animated-book" style="left: 70%; animation-delay: 5s;">&#128214;</div>
                <div class="animated-book" style="left: 85%; animation-delay: 1s; font-size: 1.8rem;">&#128215;</div>
                <div class="animated-book" style="left: 45%; animation-delay: 6s;">&#128218;</div>
            </div>

            <div class="relative z-10">
                <h3 class="text-4xl font-bold mb-4 leading-tight drop-shadow-lg">Unlock a World of Knowledge</h3>
                <p class="text-gray-200 max-w-sm drop-shadow-md">
                    Manage circulation with ease—because every book deserves to be on the right shelf.
                </p>
            </div>
        </div>
    </div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const passwordInput = document.getElementById('password');
        const usernameInput = document.getElementById('username'); // Get username input
        const toggleButton = document.getElementById('togglePasswordVisibility');
        const eyeIcon = document.getElementById('eye-icon');
        const eyeSlashIcon = document.getElementById('eye-slash-icon');

        // === 1. CLEAR AUTOFILLED FIELDS ON LOAD (The main fix) ===
        // This runs after the browser has autofilled the inputs, clearing them.
        if (usernameInput.value || passwordInput.value) {
            usernameInput.value = '';
            passwordInput.value = '';
            console.log("Autofilled credentials cleared.");
        }
        
        // === 2. PASSWORD TOGGLE LOGIC ===
        if (toggleButton) {
            toggleButton.addEventListener('click', function () {
                const isPassword = passwordInput.type === 'password';
                passwordInput.type = isPassword ? 'text' : 'password';
                eyeIcon.classList.toggle('hidden', isPassword);
                eyeSlashIcon.classList.toggle('hidden', !isPassword);
            });
        }
    });
</script>

</body>
</html>