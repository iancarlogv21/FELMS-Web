<?php
require 'email_service.php';

$data = [
    'student_name' => 'Test Student',
    'title' => 'Sample Book',
    'authors' => ['Author One'],
    'borrow_id' => 'TEST123',
    'due_date' => date('Y-m-d', strtotime('+7 days')),
    'book_thumbnail' => 'https://placehold.co/120x180'
];

$result = sendBorrowReceipt("your_real_email@gmail.com", $data);

echo $result === true ? "Email sent successfully!" : "Failed: $result";
