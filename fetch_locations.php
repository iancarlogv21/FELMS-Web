<?php
require_once __DIR__ . '/vendor/autoload.php';
use GuzzleHttp\Client;

header('Content-Type: application/json');

$endpoint = $_GET['endpoint'] ?? '';
if (!$endpoint) { echo json_encode([]); exit; }

// Ensure trailing slash for the GitLab API (Speed Fix)
if (substr($endpoint, -1) !== '/') { $endpoint .= '/'; }

$client = new Client([
    'base_uri' => 'https://psgc.gitlab.io/api/',
    'verify' => false,
    'timeout' => 15.0
]);

try {
    $response = $client->request('GET', $endpoint);
    echo $response->getBody();
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}