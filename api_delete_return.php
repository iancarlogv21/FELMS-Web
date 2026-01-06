<?php
// api_delete_return.php (CORRECTED AND ROBUST VERSION)
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

use MongoDB\BSON\ObjectId;

// Authenticate user
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
    exit;
}

// Ensure it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// Get ID from the request body
$data = json_decode(file_get_contents('php://input'), true);
$returnObjectId = $data['id'] ?? null; // This is the _id from the 'returns' collection

if (!$returnObjectId) {
    echo json_encode(['status' => 'error', 'message' => 'Transaction ID is missing.']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // --- ★★★ FIX 1: Target the 'returns' collection ★★★ ---
    // The ID belongs to a record in the 'returns' collection, not 'borrows'.
    $returnsCollection = $db->returns();
    
    $objectId = new ObjectId($returnObjectId);

    // 1. Find the transaction record using the correct object ID
    $returnRecord = $returnsCollection->findOne(['_id' => $objectId]);

    if (!$returnRecord) {
        echo json_encode(['status' => 'error', 'message' => 'Transaction record not found.']);
        exit;
    }
    
    // --- ★★★ FIX 2: Remove incorrect safety check ★★★ ---
    // This check is not needed. If it's in the 'returns' collection, it's already returned.

    // --- TRANSACTION DELETION LOGIC ---

    // 2. Delete the actual return/borrow record permanently
    $deleteResult = $returnsCollection->deleteOne(['_id' => $objectId]);

    if ($deleteResult->getDeletedCount() > 0) {
        
        // --- ★★★ FIX 3: Remove incorrect quantity logic ★★★ ---
        // We do NOT increment the book quantity. Deleting a history record
        // does not add a book back to the shelf. The book was already
        // returned and its quantity was already updated by 'api_process_return.php'.

        // Log the deletion activity with useful details
        $logDetails = "DELETED RETURN record for book '{$returnRecord['title']}' (ID: {$returnRecord['return_id']})";
        log_activity('RETURN_RECORD_DELETE', $logDetails);
        
        echo json_encode(['status' => 'success', 'message' => 'Returned transaction record cleared successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete the record.']);
    }

} catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid record ID format.']);
} catch (Exception $e) {
    // Log the error for debugging
    error_log("Error deleting return record: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred: ' . $e->getMessage()]);
}
?>