<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/vendor/autoload.php'; // Required for BSON types

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

try {
    $dbInstance = Database::getInstance();
    $borrowCollection = $dbInstance->borrows();

    $borrow_history = $borrowCollection->find([], ['sort' => ['borrow_date' => -1]]);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="borrow_history-' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    fputcsv($output, ['Borrow ID', 'ISBN', 'Title', 'Student No', 'Student Name', 'Borrow Date', 'Due Date', 'Return Date', 'Penalty']);

    foreach ($borrow_history as $doc) {
        // **FIX**: Check if dates are BSON objects and format them correctly
        $borrow_date = $doc['borrow_date'];
        if ($borrow_date instanceof \MongoDB\BSON\UTCDateTime) {
            $borrow_date = $borrow_date->toDateTime()->format('Y-m-d');
        }

        $due_date = $doc['due_date'];
        if ($due_date instanceof \MongoDB\BSON\UTCDateTime) {
            $due_date = $due_date->toDateTime()->format('Y-m-d');
        }
        
        $return_date = $doc['return_date'];
        if ($return_date instanceof \MongoDB\BSON\UTCDateTime) {
            $return_date = $return_date->toDateTime()->format('Y-m-d');
        }

        fputcsv($output, [
            $doc['borrow_id'] ?? '',
            $doc['isbn'] ?? '',
            $doc['title'] ?? '',
            $doc['student_no'] ?? '',
            $doc['student_name'] ?? '',
            $borrow_date ?? '',
            $due_date ?? '',
            $return_date ?? '',
            $doc['penalty'] ?? 0,
        ]);
    }

    fclose($output);
    exit();

} catch (Exception $e) {
    die("Error exporting data: " . $e->getMessage());
}