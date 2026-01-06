<?php
// api_delete_attendance.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['log_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

try {
    $logId = new MongoDB\BSON\ObjectId(trim($_POST['log_id']));
    $db = Database::getInstance();
    $result = $db->attendance_logs()->deleteOne(['_id' => $logId]);

    if ($result->getDeletedCount() > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Attendance record deleted successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Record not found or already deleted.']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
?>