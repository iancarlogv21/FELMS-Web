<?php
require_once __DIR__ . '/config/db.php';

try {
    // 1. Get the database instance
    $dbInstance = Database::getInstance();
    $client = $dbInstance->getClient();

    // 2. Send a 'ping' command to the server
    // This is the standard way to check if a MongoDB server is alive
    $client->selectDatabase('admin')->command(['ping' => 1]);

    echo "<h2 style='color: green;'>✅ Success! Your app is connected to MongoDB Atlas.</h2>";
    
    // 3. (Optional) Show which databases are available
    echo "<b>Available Databases:</b><ul>";
    foreach ($client->listDatabases() as $dbInfo) {
        echo "<li>" . $dbInfo->getName() . "</li>";
    }
    echo "</ul>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Connection Failed!</h2>";
    echo "<b>Error Message:</b> " . $e->getMessage();
}
?>