<?php
// test_api.php
// Simple test to check PHP's ability to fetch the API data.

$url = 'https://psgc.cloud/api/v2/provinces';

// This uses a simple built-in PHP function, which relies on the same OpenSSL config
$response = @file_get_contents($url);

if ($response === false) {
    echo "<h1>FAILED TO CONNECT TO API</h1>";
    echo "<p>PHP's cURL/OpenSSL is failing. Check Apache logs for details.</p>";
    // You can try to fetch the detailed error:
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    echo "<p>cURL Error: " . htmlspecialchars($error) . "</p>";

} else {
    echo "<h1>SUCCESS! Provinces Loaded.</h1>";
    echo "<pre>" . htmlspecialchars(substr($response, 0, 1000)) . "...</pre>";
}
?>