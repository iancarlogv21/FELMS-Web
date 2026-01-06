<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PHP Environment Check for LMS</title>
    <style>
        body { font-family: sans-serif; padding: 20px; line-height: 1.6; }
        h1, h2 { border-bottom: 2px solid #eee; padding-bottom: 10px; }
        p { margin: 10px 0; font-size: 1.1em; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        code { background-color: #f1f1f1; padding: 2px 5px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>LMS Environment Check 🛠️</h1>

    <h2>1. Checking PHP Extension...</h2>
    <?php if (extension_loaded('mongodb')): ?>
        <p class="success">✅ SUCCESS: The MongoDB PHP extension is loaded correctly.</p>
    <?php else: ?>
        <p class="error">❌ ERROR: The MongoDB PHP extension is NOT loaded.</p>
        <p><b>ACTION:</b> You must fix your <code>php.ini</code> file. Find the line <code>extension=mongodb</code>, remove the semicolon <code>;</code> at the beginning, save the file, and <b>RESTART Apache from the XAMPP Control Panel.</b></p>
        <?php exit; ?>
    <?php endif; ?>

    <hr>

    <h2>2. Checking Composer Libraries...</h2>
    <?php
    $autoload_file = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload_file)):
    ?>
        <p class="success">✅ SUCCESS: Composer's <code>vendor/autoload.php</code> file was found.</p>
        <?php require_once $autoload_file; ?>
    <?php else: ?>
        <p class="error">❌ ERROR: Composer's <code>vendor/autoload.php</code> file was NOT found.</p>
        <p><b>ACTION:</b> You must open a terminal/command-prompt, navigate to your project folder (<code>cd C:\xampp\htdocs\WebApp</code>), and run the command <code>composer install</code>.</p>
        <?php exit; ?>
    <?php endif; ?>

    <hr>

    <h2>3. Checking MongoDB Classes...</h2>
    <?php try {
        $id = new MongoDB\BSON\ObjectId();
        echo "<p class='success'>✅ SUCCESS: The MongoDB\BSON\ObjectId class is available and working!</p>";
        echo "<p>Test ObjectId: <code>" . $id . "</code></p>";
    } catch (Throwable $e) {
        echo "<p class='error'>❌ ERROR: Could not use the MongoDB\BSON\ObjectId class, even though dependencies were found.</p>";
        echo "<p><b>ACTION:</b> This is rare. Try deleting your <code>vendor</code> folder and the <code>composer.lock</code> file, then run <code>composer install</code> again.</p>";
       exit; ?>
    <?php } ?>

    <hr>

    <h2>Conclusion 🎉</h2>
    <p style="font-weight:bold; color: #28a745;">If you are seeing this message, it means all checks passed! Your server environment is now configured correctly. Your main application should now work without the 'unknown class' error.</p>

</body>
</html>