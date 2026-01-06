<?php
// api/get_student_details.php

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php'; // Adjust path if needed

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Student ID is required.']);
    exit;
}

$studentId = $_GET['id'];
$response = [];

try {
    $dbInstance = Database::getInstance();
    $studentsCollection = $dbInstance->students();
    $borrowCollection = $dbInstance->borrows();

    // 1. Get Student Details
    $student = $studentsCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($studentId)]);
    if (!$student) {
        throw new Exception('Student not found.');
    }
    $response['student_details'] = $student;
    $student_no = $student['student_no'];

    // 2. Get Total Borrows
    $response['total_borrows'] = $borrowCollection->countDocuments(['student_no' => $student_no]);

    // 3. Get Most Borrowed Book using Aggregation
    $pipeline = [
        ['$match' => ['student_no' => $student_no]],
        // --- ★★★ FIX 3: Group by the main identifier ★★★ ---
        ['$group' => ['_id' => '$book_identifier', 'borrow_count' => ['$sum' => 1]]],
        ['$sort' => ['borrow_count' => -1]],
        ['$limit' => 1],
        // --- ★★★ FIX 4: Join AddBook using both fields ★★★ ---
        ['$lookup' => [
            'from' => 'AddBook',
            'let' => ['identifier' => '$_id'],
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
        ['$unwind' => '$bookInfo'],
        ['$project' => [
            'title' => '$bookInfo.title',
            'thumbnail' => '$bookInfo.thumbnail',
            'borrow_count' => 1
        ]]
    ];
    $mostBorrowedResult = $borrowCollection->aggregate($pipeline)->toArray();
    $response['most_borrowed_book'] = $mostBorrowedResult[0] ?? null;

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()]);
}