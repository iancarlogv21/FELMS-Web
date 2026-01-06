<?php
// api/get_book_details.php

header('Content-Type: application/json');
// FIX 1: Include the helpers.php file
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers.php'; 

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Book ID is required.']);
    exit;
}

$bookId = $_GET['id'];
$response = [];

try {
    $dbInstance = Database::getInstance();
    $booksCollection = $dbInstance->books();
    $borrowCollection = $dbInstance->borrows();

    // 1. Get Book Details
    $book = $booksCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($bookId)]);
    if (!$book) {
        throw new Exception('Book not found.');
    }
    $response['book_details'] = $book;
    $isbn = $book['isbn'];

    // 2. Get Total Borrows
    // --- ★★★ FIX 1: Get Total Borrows ★★★ ---
    // Get the correct identifiers from the book record
    $book_isbn = $book['isbn'] ?? null;
    $book_acc = $book['accession_number'] ?? null;

    // Build a filter to find all borrows for this book
    $borrow_filter = ['$or' => []];
    if ($book_isbn) {
        $borrow_filter['$or'][] = ['book_identifier' => $book_isbn];
        $borrow_filter['$or'][] = ['isbn' => $book_isbn]; // Fallback for old records
    }
    if ($book_acc) {
        $borrow_filter['$or'][] = ['book_identifier' => $book_acc];
        $borrow_filter['$or'][] = ['accession_number' => $book_acc]; // Fallback for old records
    }
    // Handle case where book has no identifiers
    if (empty($borrow_filter['$or'])) {
        $borrow_filter = ['_id' => null]; // This will find 0
    }

    // 2. Get Total Borrows using the correct filter
    $response['total_borrows'] = $borrowCollection->countDocuments($borrow_filter);
    // --- END OF FIX ---

    // 3. Get Top Borrower using Aggregation
    // 3. Get Top Borrower using Aggregation
    $pipeline = [
        // --- ★★★ FIX 2: Use the correct filter ★★★ ---
        ['$match' => $borrow_filter],
        // --- END OF FIX ---
        ['$group' => ['_id' => '$student_no', 'borrow_count' => ['$sum' => 1]]],
        ['$sort' => ['borrow_count' => -1]],
        ['$limit' => 1],
        ['$lookup' => [
            'from' => 'Students',
            'localField' => '_id',
            'foreignField' => 'student_no',
            'as' => 'studentInfo'
        ]],
        ['$unwind' => '$studentInfo'],
        ['$project' => [
            'first_name' => '$studentInfo.first_name',
            'last_name' => '$studentInfo.last_name',
            'image' => '$studentInfo.image',
            // FIX 2: Added gender to the query so the helper function can use it
            'gender' => '$studentInfo.gender',
            'borrow_count' => 1
        ]]
    ];
    $topBorrowerResult = $borrowCollection->aggregate($pipeline)->toArray();
    $topBorrower = $topBorrowerResult[0] ?? null;

    // FIX 3: Process the photo URL using the helper function
    if ($topBorrower) {
        $topBorrower['photoUrl'] = getStudentPhotoUrl($topBorrower);
    }
    
    $response['top_borrower'] = $topBorrower;

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()]);
}