<?php
// api_log_attendance_exit.php (NEW FILE)

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
if (empty($student_no)) {
    send_json_response('error', 'Student Number is required.');
}

try {
    $db = Database::getInstance();
    $student = $db->students()->findOne(['student_no' => $student_no]);

    if (!$student) {
        send_json_response('error', "Student with number '{$student_no}' not found.");
    }

    // Find the student's most recent attendance log that does NOT have a time_out yet
    $latest_log = $db->attendance_logs()->findOne(
        [
            'student_no' => $student['student_no'],
            'time_out' => ['$exists' => false] 
        ],
        [
            'sort' => ['time_in' => -1] 
        ]
    );

    if (!$latest_log) {
        send_json_response('error', 'You have no open attendance record. Please log in first.');
    }

    // Update the record with a time_out
    $time_out = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $result = $db->attendance_logs()->updateOne(
        ['_id' => $latest_log['_id']],
        ['$set' => ['time_out' => new MongoDB\BSON\UTCDateTime($time_out)]]
    );

    if ($result->getModifiedCount() > 0) {
        $responseData = [
            'student_no'  => $student['student_no'],
            'full_name'   => ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''),
            'photo_url'   => getStudentPhotoUrl($student),
            'time_out'    => $time_out->format('h:i:s A'),
            'location'    => $latest_log['location'],
            'program'     => $student['program'] ?? 'N/A',
            'year'        => $student['year'] ?? 'N/A',
            'section'     => $student['section'] ?? 'N/A',
        ];
        send_json_response('success', 'Exit recorded successfully.', $responseData);
    } else {
        send_json_response('error', 'Failed to record exit. Please try again.');
    }

} catch (Exception $e) {
    send_json_response('error', 'A server error occurred: ' . $e->getMessage());
}