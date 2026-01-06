<?php
// api_process_return.php (FINAL CORRECTED VERSION)

ob_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';
session_start();

// This function now correctly includes the new_return data
function send_json_response($status, $message, $data = []) {
    ob_end_clean();
    header('Content-Type: application/json');
    $response = ['status' => $status, 'message' => $message];
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    echo json_encode($response);
    exit;
}

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    send_json_response('error', 'Unauthorized');
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || empty($_POST['borrow_id'])) {
    send_json_response('error', 'Invalid request.');
}

try {
    $borrowId = trim($_POST['borrow_id']);
    
    $db = Database::getInstance();
    $borrowsCollection = $db->borrows();
    $borrowRecord = $borrowsCollection->findOne(['borrow_id' => $borrowId]);

    if (!$borrowRecord) {
        send_json_response('error', 'Return failed. No borrow record found for this ID.');
    }

    if (!empty($borrowRecord['return_date'])) {
        send_json_response('warning', 'This book has already been returned.');
    }

    $penalty = 0;
    try {
        $due_date_obj = new DateTimeImmutable($borrowRecord['due_date']);
        $return_date_obj = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $due_date_start = $due_date_obj->setTime(0, 0, 0);
        $return_date_start = $return_date_obj->setTime(0, 0, 0);

        if ($return_date_start > $due_date_start) {
            $days_overdue = $return_date_start->diff($due_date_start)->days;
            $penalty = $days_overdue * 10;
        }
    } catch (Exception $e) {}

    $returnTimestamp = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    
    $updateResult = $borrowsCollection->updateOne(
        ['borrow_id' => $borrowId],
        ['$set' => ['return_date' => $returnTimestamp, 'penalty' => $penalty]]
    );

    if ($updateResult->getModifiedCount() > 0) {
        
        // --- ★★★ THE FIX IS HERE ★★★ ---
        // We now correctly find the book's main identifier (ISBN or Accession Number)
        // to increment its quantity back in the AddBook collection.
        $bookIdentifier = $borrowRecord['book_identifier'] ?? $borrowRecord['isbn'] ?? $borrowRecord['accession_number'];
        $identifierField = ($borrowRecord['isbn'] ?? null) ? 'isbn' : 'accession_number';

        $db->books()->updateOne(
            [$identifierField => $bookIdentifier],
            ['$inc' => ['quantity' => 1]]
        );

        $newReturnRecord = [
            'return_id'   => 'R' . date('YmdHis'),
            'borrow_id'   => $borrowId,
            'student_no'  => (string)$borrowRecord['student_no'],
            
            // --- ★★★ AND HERE ★★★ ---
            // We save BOTH identifiers to the returns collection.
            'isbn'        => $borrowRecord['isbn'] ?? null,
            'accession_number' => $borrowRecord['accession_number'] ?? null,
            // --- End Fix ---
            
            'title'       => (string)$borrowRecord['title'],
            'return_date' => $returnTimestamp,
            'penalty'     => $penalty
        ];
        
        // We also need to get the Mongo _id to send back to the front-end
        $insertResult = $db->returns()->insertOne($newReturnRecord);
        $newReturnRecord['_id'] = $insertResult->getInsertedId(); // Add the new ID to the array
        
        log_activity('BOOK_RETURN', "Book '{$newReturnRecord['title']}' was returned by student {$newReturnRecord['student_no']}.");
        
        send_json_response('success', 'Book successfully returned!', ['new_return' => $newReturnRecord]);
        
    } else {
        send_json_response('error', 'Failed to update the borrow record.');
    }

} catch (Exception $e) {
    send_json_response('error', 'A server error occurred: ' . $e->getMessage());
}
?>