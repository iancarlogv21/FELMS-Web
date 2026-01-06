<?php
// api_log_attendance.php (CORRECTED FOR ENTRANCE)

header('Content-Type: application/json');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

function send_json_response($status, $message, $data = []) {
    $response = ['status' => $status, 'message' => $message];
    if (!empty($data)) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response('error', 'Invalid request method.');
}

$student_no = trim($_POST['student_no'] ?? '');
$location = trim($_POST['location'] ?? 'Main Campus Library');

if (empty($student_no)) {
    send_json_response('error', 'Student Number is required.');
}

try {
    $db = Database::getInstance();
    $student = $db->students()->findOne(['student_no' => $student_no]);

    if (!$student) {
        send_json_response('error', "Student with number '{$student_no}' not found.");
    }
    
    // This is the correct logic for an ENTRANCE
    $time_in = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $newLog = [
        'student_no' => $student['student_no'],
        'location'   => $location,
        'time_in'    => new MongoDB\BSON\UTCDateTime($time_in),
    ];

    $result = $db->attendance_logs()->insertOne($newLog);

    if ($result->getInsertedCount() > 0) {
        $responseData = [
            'student_no'  => $student['student_no'],
            'full_name'   => ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''),
            'photo_url'   => getStudentPhotoUrl($student),
            'time_in'     => $time_in->format('h:i:s A'),
            'date_in'     => $time_in->format('F j, Y'),
            'location'    => $location,
            'program'     => $student['program'] ?? 'N/A',
            'year'        => $student['year'] ?? 'N/A',
            'section'     => $student['section'] ?? 'N/A',
        ];
        send_json_response('success', 'Entrance recorded successfully.', $responseData);
    } else {
        send_json_response('error', 'Failed to save attendance record.');
    }

} catch (Exception $e) {
    send_json_response('error', 'A server error occurred: ' . $e->getMessage());
}