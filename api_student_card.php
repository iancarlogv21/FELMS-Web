<?php
header('Content-Type: application/json');
session_start();

// --- Essential File Includes ---
// Correct path for the Composer autoload file, assuming it's in the project root.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

use Picqer\Barcode\BarcodeGeneratorPNG;

// --- Authentication & Input Validation ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Authentication required. Please log in.']);
    exit;
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Student ID is required.']);
    exit;
}

try {
    // --- Fetch Student Data ---
    $dbInstance = Database::getInstance();
    $studentsCollection = $dbInstance->students();
    $studentId = new MongoDB\BSON\ObjectId($_GET['id']);
    $student = $studentsCollection->findOne(['_id' => $studentId]);

    if (!$student) {
        http_response_code(404); // Not Found
        echo json_encode(['error' => 'Student not found.']);
        exit;
    }
    
    // --- Generate Barcode ---
    $generator = new BarcodeGeneratorPNG();
    $student_no = $student['student_no'] ?? 'N/A';
    
    // Prevent barcode generation error if student number is missing
    if ($student_no === 'N/A' || empty($student_no)) {
        $barcodeBase64 = ''; // Send an empty string for the barcode
    } else {
        $barcodeImage = $generator->getBarcode($student_no, $generator::TYPE_CODE_128, 2, 60);
        $barcodeBase64 = 'data:image/png;base64,' . base64_encode($barcodeImage);
    }

    // --- Prepare Data for JSON Response ---
    $responseData = [
        'fullName'      => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
        'student_no'    => $student_no,
        'program'       => $student['program'] ?? 'N/A',
        'photoUrl'      => getStudentPhotoUrl($student), // Correctly calls your helper function
        'barcodeBase64' => $barcodeBase64
    ];
    
    echo json_encode($responseData);

} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    // Log the actual error for your records, but send a generic message to the user
    error_log("API Error in api_student_card.php: " . $e->getMessage());
    echo json_encode(['error' => 'An unexpected server error occurred. Please check the logs.']);
}

