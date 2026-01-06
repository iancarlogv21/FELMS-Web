<?php
// api_get_borrow_details.php (CORRECTED AND FINAL VERSION)

header('Content-Type: application/json');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';
session_start();

// Security: Ensure the user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if (empty($_GET['borrow_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Borrow ID is required.']);
    exit;
}

$borrowId = trim($_GET['borrow_id']);
$db = Database::getInstance();
$borrowsCollection = $db->borrows();

// This is a more efficient way to get all data in one database call
$pipeline = [
    ['$match' => ['borrow_id' => $borrowId]],
    ['$limit' => 1],
    ['$lookup' => [
        'from' => 'Students',
        'localField' => 'student_no',
        'foreignField' => 'student_no',
        'as' => 'studentInfo'
    ]],
    
    // --- ★★★ THIS IS THE FIX ★★★ ---
    // Look up the book by its *main identifier* from the borrow record,
    // which could be an ISBN or an Accession Number.
    ['$lookup' => [
        'from' => 'AddBook',
        // Use the 'book_identifier' field from the 'borrows' collection
        'let' => ['identifier' => '$book_identifier'], 
        'pipeline' => [
            ['$match' => [
                '$expr' => ['$or' => [
                    ['$eq' => ['$isbn', '$$identifier']],
                    ['$eq' => ['$accession_number', '$$identifier']]
                ]]
            ]],
            ['$limit' => 1]
        ],
        'as' => 'bookInfo'
    ]],
    // --- End Fix ---
    
    ['$unwind' => ['path' => '$studentInfo', 'preserveNullAndEmptyArrays' => true]],
    ['$unwind' => ['path' => '$bookInfo', 'preserveNullAndEmptyArrays' => true]]
];

$cursor = $borrowsCollection->aggregate($pipeline);
$borrowRecord = current($cursor->toArray());

if (!$borrowRecord) {
    echo json_encode(['status' => 'error', 'message' => "No transaction found with ID: $borrowId"]);
    exit;
}

if (!empty($borrowRecord['return_date'])) {
    echo json_encode(['status' => 'warning', 'message' => 'This book has already been returned.']);
    exit;
}

// Prepare data for a successful response
$studentName = 'N/A';
if (isset($borrowRecord['studentInfo'])) {
    $studentName = ($borrowRecord['studentInfo']['first_name'] ?? '') . ' ' . ($borrowRecord['studentInfo']['last_name'] ?? '');
}

$data = [
    // Use the 'title' from the joined book, but fallback to the 'title' saved on the borrow record
    'title'         => $borrowRecord['bookInfo']['title'] ?? $borrowRecord['title'] ?? 'Unknown Title',
    'student_name'  => $studentName,
    'book_cover'    => $borrowRecord['bookInfo']['thumbnail'] ?? 'https://placehold.co/80x120/E2E8F0/4A5568?text=No+Cover',
    'student_photo' => getStudentPhotoUrl($borrowRecord['studentInfo'] ?? null),
    'borrow_date'   => isset($borrowRecord['borrow_date']) ? (new DateTime($borrowRecord['borrow_date']))->format('M d, Y') : 'N/A',
    'due_date'      => isset($borrowRecord['due_date']) ? (new DateTime($borrowRecord['due_date']))->format('M d, Y') : 'N/A',
];

echo json_encode(['status' => 'success', 'data' => $data]);