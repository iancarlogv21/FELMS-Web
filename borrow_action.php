<?php
// Start output buffering to capture any stray output
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/email_service.php';
require_once __DIR__ . '/helpers.php';



use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

// Function to send a clean JSON response and exit
function send_json_response(array $data) {
    // Clear the output buffer, discarding any notices/warnings
    ob_end_clean();
    // Set the header and echo the clean JSON
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    send_json_response(['success' => false, 'message' => 'Unauthorized']);
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    send_json_response(['success' => false, 'message' => 'Invalid request method.']);
}

$action = $_POST['action'] ?? null;

try {
    $dbInstance = Database::getInstance();

    switch ($action) {
        case 'fetch_book':
            handle_fetch_book($dbInstance);
            break;
        case 'fetch_student':
            handle_fetch_student($dbInstance);
            break;
        case 'save':
            handle_save_borrow($dbInstance);
            break;
        case 'delete':
            handle_delete_borrow($dbInstance);
            break;
        case 'send_receipt':
            handle_send_receipt($dbInstance);
            break;
        case 'send_overdue_reminder':
            handle_send_overdue_reminder($dbInstance);
            break;    
        case 'send_proactive_reminder': // <--- ADD THIS NEW CASE
            handle_send_proactive_reminder($dbInstance);
            break;
        case 'delete_returned_record': // <--- ADD THIS LINE
        handle_delete_returned_record($dbInstance); // <--- ADD THIS LINE
        break; // <--- ADD THIS LINE
                
        default:
            throw new Exception('Invalid action specified.');
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    send_json_response(['success' => false, 'message' => 'A server error occurred: ' . $e->getMessage()]);
}


function handle_delete_returned_record($dbInstance) {
    $mongoId = $_POST['_id'] ?? null;
    if (!$mongoId) { throw new Exception('Transaction ID is missing.'); }

    $borrowCollection = $dbInstance->borrows();
    
    // CRITICAL: Check if the return_date is set (meaning the book has been returned).
    $transaction = $borrowCollection->findOne(['_id' => new ObjectId($mongoId)]);
    
    if (!$transaction) {
        throw new Exception('Transaction not found.');
    }

    if (empty($transaction['return_date'])) {
        throw new Exception('Cannot delete. This book has not been marked as returned.');
    }
    
    // Proceed with permanent deletion since the book is returned and the record is historical.
    $deleteResult = $borrowCollection->deleteOne(['_id' => new ObjectId($mongoId)]);
    
    if ($deleteResult->getDeletedCount() > 0) {
        send_json_response(['success' => true, 'message' => 'Returned transaction record cleared successfully.']);
    } else {
        throw new Exception('Failed to delete transaction record.');
    }
}



function handle_fetch_book($dbInstance) {
    // The identifier field in the POST data is used for both ISBN and Accession Number
    $identifier = $_POST['isbn'] ?? ''; 
    if (empty($identifier)) { 
        send_json_response(['success' => false, 'message' => 'Book identifier is required.']); 
    }

    $booksCollection = $dbInstance->books();
    
    // --- CRITICAL FIX: Search by EITHER ISBN OR Accession Number ---
    $book = $booksCollection->findOne([
        '$or' => [
            // Search 1: Check the isbn field
            ['isbn' => $identifier],
            // Search 2: Check the accession_number field
            ['accession_number' => $identifier]
        ]
    ]);
    // --- END CRITICAL FIX ---

    if (!$book) {
        send_json_response(['success' => false, 'message' => "No book found with identifier: $identifier."]);
    }
    
    // Convert BSON types to a standard array for clean JSON output
    $book_array = json_decode(json_encode($book), true);
    send_json_response(['success' => true, 'book' => $book_array]);
}

function handle_fetch_student($dbInstance) {
    $student_no = $_POST['student_no'] ?? '';
    if (empty($student_no)) { send_json_response(['success' => false, 'message' => 'Student number is required.']); }

    $studentsCollection = $dbInstance->students();
    $student = $studentsCollection->findOne(['student_no' => $student_no]);

    if (!$student) {
        send_json_response(['success' => false, 'message' => "No student found with number: $student_no."]);
    }
    
    $borrowCollection = $dbInstance->borrows();
    // Count only books that have not been returned
    $activeBorrowsCount = $borrowCollection->countDocuments([
        'student_no' => $student_no,
        'return_date' => null
    ]);

    // Get total borrow history for display
    $totalBorrowCount = $borrowCollection->countDocuments(['student_no' => $student_no]);

    $student_array = json_decode(json_encode($student), true);
    // Add eligibility flag based on the 3-book limit
    $student_array['is_eligible'] = $activeBorrowsCount < 3;
    // **FIX**: Add the processed photo URL using the helper function
    $student_array['photoUrl'] = getStudentPhotoUrl($student);

    send_json_response([
        'success' => true, 
        'student' => $student_array,
        'borrow_count' => $totalBorrowCount
    ]);
}

function handle_save_borrow($dbInstance) {
    $identifier = $_POST['isbn'] ?? null;
    $student_no = $_POST['student_no'] ?? null;
    $borrow_date_str = $_POST['borrow_date'] ?? null;
    $due_date_str = $_POST['due_date'] ?? null;

    $booksCollection = $dbInstance->books();
    $studentsCollection = $dbInstance->students();
    $borrowCollection = $dbInstance->borrows();
    
    $book = $booksCollection->findOne([
        '$or' => [
            ['isbn' => $identifier],
            ['accession_number' => $identifier]
        ]
    ]);
    
    if (!$book || $book['quantity'] < 1) { throw new Exception('Book is not available or does not exist.'); }
    
    $student = $studentsCollection->findOne(['student_no' => $student_no]);
    if (!$student) { throw new Exception('Student not found.'); }

    $activeBorrowsCount = $borrowCollection->countDocuments(['student_no' => $student_no, 'return_date' => null]);
    if ($activeBorrowsCount >= 3) {
        throw new Exception('Student has reached the maximum borrowing limit of 3 books.');
    }

    // --- ID GENERATION: CONTENTION-FREE HASH ID ---
    
    // Set the Timezone to Asia/Manila (used for the final borrow date later)
    $timezone = new DateTimeZone('Asia/Manila');
    $currentDate = new DateTime('now', $timezone);
    $date_prefix = $currentDate->format('ymd'); // YYMMDD format (e.g., 251102)

    // Generate a highly unique 5-character hash suffix (avoids counting issues)
    $unique_hash = substr(md5(uniqid(rand(), true)), 0, 5);

    // Construct the unique ID: TXN-YYMMDD-HASH (e.g., TXN-251102-a4e9f)
    $borrow_id = '' . $date_prefix . '' . $unique_hash;
    // --- END ID GENERATION ---
    
    
    $timezone = new DateTimeZone('Asia/Manila');
    $borrowDate = new DateTime($borrow_date_str, $timezone);
    $dueDate = new DateTime($due_date_str, $timezone);

    $bookIdentifierToSave = $book['accession_number'] ?? $book['isbn'] ?? null;
    $isbnForBorrowRecord = $book['accession_number'] ? null : ($book['isbn'] ?? null);

    // FIX 1: Determine the best available image URL from the AddBook collection
    $bookCoverUrl = $book['thumbnail'] ?? $book['cover_url'] ?? null;

    $borrowRecord = [
        'borrow_id' => $borrow_id, 
        'isbn' => $isbnForBorrowRecord, 
        'accession_number' => $book['accession_number'] ?? null,
        'book_identifier' => $bookIdentifierToSave,
        'title' => $book['title'],
        
        // FIX 2: Save the resolved image URL to both fields for consistency
        'cover_url' => $bookCoverUrl,
        'thumbnail' => $bookCoverUrl, 
        
        'student_no' => $student['student_no'], 
        'student_name' => ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''),
        'borrow_date' => $borrowDate->format('Y-m-d H:i:s'), 
        'due_date' => $dueDate->format('Y-m-d'),
        'barcode_data' => $borrow_id, 'penalty' => 0, 'return_date' => null,
        'created_at' => new UTCDateTime()
    ];

    $insertResult = $borrowCollection->insertOne($borrowRecord);

    if ($insertResult->getInsertedCount() > 0) {
        log_activity('BORROW_BOOK', "Book '{$book['title']}' was issued to student {$student['first_name']} {$student['last_name']} ({$student['student_no']}).");
    }
       
    $booksCollection->updateOne(['_id' => $book['_id']], ['$inc' => ['quantity' => -1]]);
    
    // --- Post-Save Aggregation for Frontend Display (Used for AJAX table refresh) ---
    $newId = $insertResult->getInsertedId();
    $pipeline = [
        ['$match' => ['_id' => $newId]],
        ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
        
        ['$lookup' => [
            'from' => 'AddBook', 
            'let' => ['id' => '$book_identifier'], // Use the stored unique identifier
            'pipeline' => [
                ['$match' => [
                    '$expr' => ['$or' => [
                        ['$eq' => ['$$id', '$isbn']],        // Match by ISBN
                        ['$eq' => ['$$id', '$accession_number']] // Match by Accession Number
                    ]]
                ]],
                ['$limit' => 1] 
            ],
            'as' => 'book_details'
        ]],
        // Use unwind to flatten the arrays. The book_details array will only have one element now.
        ['$unwind' => ['path' => '$student_details', 'preserveNullAndEmptyArrays' => true]],
        ['$unwind' => ['path' => '$book_details', 'preserveNullAndEmptyArrays' => true]],

        ['$project' => [
            'borrow_id' => 1, 'isbn' => 1, 'accession_number' => 1, 'book_identifier' => 1, 
            'title' => 1, 'cover_url' => 1, 'student_no' => 1, 'student_name' => 1, 
            'borrow_date' => 1, 'due_date' => 1, 'barcode_data' => 1, 'penalty' => 1, 
            'return_date' => 1, 'created_at' => 1,
            
            'student_details' => 1,
            'book_details' => 1,
            
            'thumbnail' => '$thumbnail',
            'cover_url_joined' => '$cover_url',
            
            'display_identifier' => ['$ifNull' => ['$isbn', '$accession_number']]
        ]]
    ];
    
    $cursor = $borrowCollection->aggregate($pipeline);
    $newTransaction = $cursor->toArray()[0] ?? null;

    
    if ($newTransaction && isset($newTransaction['student_details'])) {
        $studentDetails = (array) $newTransaction['student_details'];
        $studentDetails['photoUrl'] = getStudentPhotoUrl($studentDetails);
        $newTransaction['student_details'] = $studentDetails;
    }

    $message = 'Book issued successfully!';
    if (!empty($student['email'])) {
        try {
            $receiptData = [
                'receipt_id'   => $borrow_id, 
                'barcode_data' => $borrow_id,
                'title'        => $book['title'] ?? 'Unknown Book',
                'student_name' => $borrowRecord['student_name'], 'student_no' => $student_no,
                'borrow_date' => $borrowDate->format('M d, Y h:i A'), 'due_date' => $dueDate->format('M d, Y')
            ];
            sendBorrowReceipt($student['email'], $receiptData);
            $message .= ' Receipt sent to email.';
        } catch (Exception $e) {
            $message .= ' Could not send email: ' . $e->getMessage();
        }
    } else {
        $message .= ' No student email on record.';
    }

    send_json_response(['success' => true, 'message' => $message, 'new_transaction' => $newTransaction]);
}


function handle_send_receipt($dbInstance) {
    $mongoId = $_POST['_id'] ?? null;
    if (!$mongoId) { throw new Exception('Transaction ID is missing.'); }

    $borrowCollection = $dbInstance->borrows();
    $studentsCollection = $dbInstance->students();

    $transaction = $borrowCollection->findOne(['_id' => new ObjectId($mongoId)]);
    if (!$transaction) { throw new Exception('Transaction not found.'); }

    $student = $studentsCollection->findOne(['student_no' => $transaction['student_no']]);
    if (!$student || empty($student['email'])) { throw new Exception('Student email not found on record.'); }

    $receiptData = [
        'receipt_id'   => $transaction['borrow_id'] ?? 'N/A',
        'barcode_data' => $transaction['barcode_data'] ?? $transaction['borrow_id'] ?? 'N/A',
        'isbn'         => $transaction['isbn'] ?? 'N/A',
        'title'        => $transaction['title'] ?? 'Unknown Title',
        'student_name' => $transaction['student_name'] ?? 'Unknown Student',
        'student_no'   => $transaction['student_no'] ?? 'N/A',
        'borrow_date'  => (string)($transaction['borrow_date'] ?? ''),
        'due_date'     => (string)($transaction['due_date'] ?? '')
    ];
    
    if (sendBorrowReceipt($student['email'], $receiptData)) {
        send_json_response(['success' => true, 'message' => 'Receipt sent to ' . $student['email']]);
    } else {
        throw new Exception('Failed to send receipt for an unknown reason.');
    }
}

function handle_delete_borrow($dbInstance) {
    $mongoId = $_POST['_id'] ?? null;
    if (!$mongoId) { throw new Exception('Transaction ID is missing.'); }
    $borrowCollection = $dbInstance->borrows();
    $booksCollection = $dbInstance->books();
    $transaction = $borrowCollection->findOne(['_id' => new ObjectId($mongoId)]);
    if (!$transaction) { throw new Exception('Transaction not found.'); }
    $deleteResult = $borrowCollection->deleteOne(['_id' => new ObjectId($mongoId)]);
    if ($deleteResult->getDeletedCount() > 0) {
        if (empty($transaction['return_date'])) {
            $booksCollection->updateOne(['isbn' => $transaction['isbn']], ['$inc' => ['quantity' => 1]]);
        }
        send_json_response(['success' => true, 'message' => 'Transaction deleted successfully.']);
    } else {
        throw new Exception('Failed to delete transaction.');
    }
}


/**
 * Handles sending an overdue book reminder, including penalty and days overdue.
 */
function handle_send_overdue_reminder($dbInstance) {
    // Ensure all required classes are loaded (PHPMailer, MongoDB\BSON\ObjectId, etc.)
    // Assuming required_once statements are at the top of borrow_action.php

    $borrowId = $_POST['borrow_id'] ?? null;
    if (!$borrowId) {
        send_json_response(['success' => false, 'message' => 'Borrow ID is missing.']);
    }

    $borrowObjectId = new MongoDB\BSON\ObjectId($borrowId);
    $borrowCollection = $dbInstance->borrows();

    $pipeline = [
        ['$match' => ['_id' => $borrowObjectId]],
        ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
        ['$lookup' => [
            'from' => 'AddBook', 
            'let' => ['id' => '$book_identifier', 'fallback_isbn' => '$isbn', 'fallback_accession' => '$accession_number'], 
            'pipeline' => [
                ['$match' => [
                    '$expr' => ['$or' => [
                        ['$eq' => ['$$id', '$isbn']], // New records use book_identifier
                        ['$eq' => ['$$id', '$accession_number']],
                        ['$eq' => ['$$fallback_isbn', '$isbn']], // Old records fallback 1
                        ['$eq' => ['$$fallback_accession', '$accession_number']], // Old records fallback 2
                    ]]
                ]],
                ['$limit' => 1] 
            ],
            'as' => 'book_details'
        ]],
        ['$unwind' => '$student_details'],
        ['$unwind' => '$book_details']
    ];
    $details = $borrowCollection->aggregate($pipeline)->toArray()[0] ?? null;

    if (!$details) {
        throw new Exception('Overdue record not found.');
    }

    $studentName = $details['student_details']['first_name'];
    $studentEmail = $details['student_details']['email'];
    $bookTitle = $details['book_details']['title'];
    
    // --- DATE AND PENALTY CALCULATION (FIXED) ---
    // 1. Create DateTime objects for proper calculation
    $dueDateObj = new DateTime($details['due_date']);
    $today = new DateTime('now', new DateTimeZone('Asia/Manila'));
    
    // 2. Define the formatted date for the email
    $dueDateFormatted = $dueDateObj->format('F j, Y'); 
    
    // 3. Calculate overdue days and penalty
    $daysOverdue = $today->diff($dueDateObj)->days; 
    $calculatedPenalty = $daysOverdue * 10; // Assuming ₱10 per day rate
    $penaltyText = '₱' . number_format($calculatedPenalty, 2);
    
    // --- PHPMailer Configuration ---
    $mail = new PHPMailer(true);

    // Server settings from your email_config.php file
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = SMTP_ENCRYPTION === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = SMTP_PORT;

    // Recipients
    $mail->setFrom('no-reply@yourlibrary.com', 'FELMS Library');
    $mail->addAddress($studentEmail, $studentName);

    //Content
    $mail->isHTML(true);
    $mail->Subject = 'URGENT: Overdue Book Notice from FELMS Library';

    // START: New Modern Email Body
    $mail->Body    = "
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Overdue Book Notice</title>
</head>
<body style='margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f7f6;'>
    <table border='0' cellpadding='0' cellspacing='0' width='100%'>
        <tr>
            <td style='padding: 20px 0;'>
                <table align='center' border='0' cellpadding='0' cellspacing='0' width='600' style='border-collapse: collapse; background-color: #ffffff; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.08);'>
                    <tr>
                        <td align='center' style='background-color: #ef4444; color: #ffffff; padding: 30px; border-top-left-radius: 12px; border-top-right-radius: 12px;'>
                            <h1 style='margin: 0; font-size: 28px; font-weight: bold;'>URGENT: Overdue Book Notice</h1>
                            <p style='margin: 5px 0 0; font-size: 16px;'>Fast & Efficient LMS</p>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding: 40px 30px;'>
                            <h2 style='margin: 0 0 20px; font-size: 24px; color: #1e293b;'>Overdue Book Notice</h2>
                            <p style='margin: 0 0 15px; font-size: 16px; line-height: 1.6; color: #475569;'>Dear {$studentName},</p>
                            <p style='margin: 0 0 25px; font-size: 16px; line-height: 1.6; color: #475569;'>This is an urgent reminder that the following book is now {$daysOverdue} day(s) overdue. Please return it to the library immediately to prevent further penalty charges.</p>
                            
                            <table border='0' cellpadding='0' cellspacing='0' width='100%' style='background-color: #fee2e2; border-radius: 8px; padding: 20px; border: 1px solid #fca5a5;'>
                                <tr>
                                    <td>
                                        <p style='margin: 0 0 8px; font-size: 14px; color: #64748b;'>BOOK TITLE</p>
                                        <h3 style='margin: 0; font-size: 18px; color: #0f172a;'>\"{$bookTitle}\"</h3>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding-top: 15px;'>
                                        <p style='margin: 0 0 8px; font-size: 14px; color: #64748b;'>DUE DATE</p>
                                        <p style='margin: 0; font-size: 18px; font-weight: bold; color: #ef4444;'>{$dueDateFormatted}</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding-top: 15px; border-top: 1px dashed #fca5a5;'>
                                        <p style='margin: 0 0 8px; font-size: 14px; color: #64748b;'>CURRENT PENALTY (as of today)</p>
                                        <p style='margin: 0; font-size: 20px; font-weight: bold; color: #b91c1c;'>{$penaltyText}</p>
                                    </td>
                                </tr>
                            </table>

                            <p style='margin: 25px 0 0; font-size: 16px; line-height: 1.6; color: #475569;'>We appreciate your attention to this urgent request.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style='background-color: #f8fafc; padding: 30px; text-align: center; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;'>
                            <p style='margin: 0; font-size: 14px; color: #94a3b8;'>&copy; " . date('Y') . " FELMS Library. All rights reserved.</p>
                            <p style='margin: 5px 0 0; font-size: 12px; color: #cbd5e1;'>123 Library Lane, Knowledge City, 12345</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
";

    $mail->send();
    send_json_response(['success' => true, 'message' => 'Reminder email sent successfully to ' . $studentEmail]);
}
// END: New Modern Email Body




 
function handle_send_proactive_reminder($dbInstance) {
    $borrowId = $_POST['borrow_id'] ?? null;
    if (!$borrowId) {
        send_json_response(['success' => false, 'message' => 'Borrow ID is missing.']);
    }

    $borrowObjectId = new MongoDB\BSON\ObjectId($borrowId);
    $borrowCollection = $dbInstance->borrows();

    $pipeline = [
        ['$match' => ['_id' => $borrowObjectId]],
        ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
        ['$lookup' => [
            'from' => 'AddBook', 
            'let' => ['id' => '$book_identifier', 'fallback_isbn' => '$isbn', 'fallback_accession' => '$accession_number'], 
            'pipeline' => [
                ['$match' => [
                    '$expr' => ['$or' => [
                        ['$eq' => ['$$id', '$isbn']], 
                        ['$eq' => ['$$id', '$accession_number']],
                        ['$eq' => ['$$fallback_isbn', '$isbn']],
                        ['$eq' => ['$$fallback_accession', '$accession_number']],
                    ]]
                ]],
                ['$limit' => 1] 
            ],
            'as' => 'book_details'
        ]],
        ['$unwind' => '$student_details'],
        ['$unwind' => '$book_details']
    ];
    $details = $borrowCollection->aggregate($pipeline)->toArray()[0] ?? null;

    if (!$details) {
        throw new Exception('Borrow record not found.');
    }

    $studentName = $details['student_details']['first_name'];
    $studentEmail = $details['student_details']['email'];
    $bookTitle = $details['book_details']['title'];
    
    $dueDate = new DateTime($details['due_date']);
    $dueDateFormatted = $dueDate->format('F j, Y');
    
    $today = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $daysLeft = $today->diff($dueDate)->days;

    // --- PHPMailer Configuration ---
    $mail = new PHPMailer(true);
    // ... (SMTP settings) ...
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = SMTP_ENCRYPTION === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = SMTP_PORT;

    // Recipients
    $mail->setFrom('no-reply@yourlibrary.com', 'FELMS Library');
    $mail->addAddress($studentEmail, $studentName);

    //Content
    $mail->isHTML(true);
    $mail->Subject = 'Library Reminder: Your Book is Due Soon!';
    
    // START: New Proactive Email Body
    $mail->Body = "
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Book Due Reminder</title>
</head>
<body style='margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f7f6;'>
    <table border='0' cellpadding='0' cellspacing='0' width='100%'>
        <tr>
            <td style='padding: 20px 0;'>
                <table align='center' border='0' cellpadding='0' cellspacing='0' width='600' style='border-collapse: collapse; background-color: #ffffff; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.08);'>
                    <tr>
                        <td align='center' style='background-color: #0ea5e9; color: #ffffff; padding: 30px; border-top-left-radius: 12px; border-top-right-radius: 12px;'>
                            <h1 style='margin: 0; font-size: 28px; font-weight: bold;'>Book Due Soon</h1>
                            <p style='margin: 5px 0 0; font-size: 16px;'>Fast & Efficient LMS</p>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding: 40px 30px;'>
                            <h2 style='margin: 0 0 20px; font-size: 24px; color: #1e293b;'>Friendly Reminder</h2>
                            <p style='margin: 0 0 15px; font-size: 16px; line-height: 1.6; color: #475569;'>Dear {$studentName},</p>
                            <p style='margin: 0 0 25px; font-size: 16px; line-height: 1.6; color: #475569;'>This is a reminder that the following book is due in {$daysLeft} day(s). Please return it on or before the due date to avoid any penalty charges.</p>
                            
                            <table border='0' cellpadding='0' cellspacing='0' width='100%' style='background-color: #e0f2fe; border-radius: 8px; padding: 20px; border: 1px solid #bae6fd;'>
                                <tr>
                                    <td>
                                        <p style='margin: 0 0 8px; font-size: 14px; color: #64748b;'>BOOK TITLE</p>
                                        <h3 style='margin: 0; font-size: 18px; color: #0f172a;'>\"{$bookTitle}\"</h3>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding-top: 15px;'>
                                        <p style='margin: 0 0 8px; font-size: 14px; color: #64748b;'>DUE DATE</p>
                                        <p style='margin: 0; font-size: 18px; font-weight: bold; color: #0ea5e9;'>{$dueDateFormatted}</p>
                                    </td>
                                </tr>
                            </table>

                            <p style='margin: 25px 0 0; font-size: 16px; line-height: 1.6; color: #475569;'>We encourage you to return it soon. Thank you!</p>
                        </td>
                    </tr>
                    <tr>
                        <td style='background-color: #f8fafc; padding: 30px; text-align: center; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;'>
                            <p style='margin: 0; font-size: 14px; color: #94a3b8;'>&copy; " . date('Y') . " FELMS Library. All rights reserved.</p>
                            <p style='margin: 5px 0 0; font-size: 12px; color: #cbd5e1;'>123 Library Lane, Knowledge City, 12345</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
";
    // END: New Proactive Email Body

    $mail->send();
    send_json_response(['success' => true, 'message' => 'Proactive reminder email sent successfully to ' . $studentEmail]);
}
// ...