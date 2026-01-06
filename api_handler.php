<?php
// --- FOR DEBUGGING ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
// --------------------

session_start();
header('Content-Type: application/json'); 
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    // **FIXED**: Get the database instance and use the correct methods from your db.php file
    $dbInstance = Database::getInstance();

    if ($action === 'getBook' && isset($_GET['isbn'])) {
        $booksCollection = $dbInstance->books(); // Uses the books() method which points to AddBook
        $book = $booksCollection->findOne(['isbn' => $_GET['isbn']]);

        if ($book) {
            if (isset($book['quantity']) && $book['quantity'] > 0) {
                echo json_encode(['success' => true, 'book' => $book]);
            } else {
                echo json_encode(['success' => false, 'message' => 'This book is currently unavailable (0 quantity).']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Book with this ISBN not found in the database.']);
        }
        exit;
    }

    if ($action === 'getStudent' && isset($_GET['student_no'])) {
        $studentsCollection = $dbInstance->students();
        $borrowCollection = $dbInstance->borrows();
        $student = $studentsCollection->findOne(['student_no' => $_GET['student_no']]);

        if ($student) {
            $borrowCount = $borrowCollection->countDocuments(['student_no' => $_GET['student_no'], 'return_date' => null]);
            if ($borrowCount >= 3) {
                 echo json_encode(['success' => false, 'message' => 'Student has reached the maximum borrow limit of 3 books.']);
            } else {
                echo json_encode(['success' => true, 'student' => $student, 'borrowCount' => $borrowCount]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Student with this number not found.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
}