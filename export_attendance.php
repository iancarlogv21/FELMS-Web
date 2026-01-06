<?php
// export_attendance.php
session_start();
require_once __DIR__ . '/vendor/autoload.php'; // Make sure this path is correct
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// =========================================================================
// == REUSE THE EXACT SAME FILTER LOGIC FROM attendance_log.php
// =========================================================================
$dbInstance = Database::getInstance();
$logsCollection = $dbInstance->attendance_logs();

$search_query = trim($_GET['search'] ?? '');
$filter_program = trim($_GET['program'] ?? '');
$filter_status = trim($_GET['status'] ?? '');

$pipeline = [
    ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'studentInfo']],
    ['$unwind' => ['path' => '$studentInfo', 'preserveNullAndEmptyArrays' => true]],
];
$match = [];
if (!empty($search_query)) {
    $match['$or'] = [
        ['studentInfo.first_name' => ['$regex' => $search_query, '$options' => 'i']],
        ['studentInfo.last_name' => ['$regex' => $search_query, '$options' => 'i']],
        ['student_no' => ['$regex' => $search_query, '$options' => 'i']]
    ];
}
if (!empty($filter_program)) { $match['studentInfo.program'] = $filter_program; }
if ($filter_status === 'in_library') { $match['time_out'] = ['$exists' => false]; } 
elseif ($filter_status === 'completed') { $match['time_out'] = ['$exists' => true]; }

if (!empty($match)) { $pipeline[] = ['$match' => $match]; }
$pipeline[] = ['$sort' => ['time_in' => -1]];
$logs = iterator_to_array($logsCollection->aggregate($pipeline));

// =========================================================================
// == SPREADSHEET GENERATION LOGIC
// =========================================================================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// --- Define Styles ---
$titleStyle = [
    'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '1E3A8A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
];
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4B5563']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
];
$dateStyle = [
    'font' => ['size' => 10, 'color' => ['rgb' => '4B5563']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
];

// --- Apply Title & Header Styles ---
$sheet->mergeCells('A1:G1');
$sheet->setCellValue('A1', 'FELMS - Student Attendance Report');
$sheet->getStyle('A1')->applyFromArray($titleStyle);

$sheet->mergeCells('A2:G2');
$sheet->setCellValue('A2', 'Generated on: ' . date('F j, Y, g:i a'));
$sheet->getStyle('A2')->applyFromArray($dateStyle);

$headers = ['Student Name', 'Student No.', 'Program', 'Time In', 'Time Out', 'Duration', 'Location'];
$sheet->fromArray($headers, NULL, 'A4'); // Start headers at row 4
$sheet->getStyle('A4:G4')->applyFromArray($headerStyle);

// --- Populate Data ---
$row = 5; // Start data at row 5
foreach ($logs as $log) {
    $timeIn = $log['time_in']->toDateTime()->setTimezone(new DateTimeZone('Asia/Manila'));
    $timeOut = isset($log['time_out']) ? $log['time_out']->toDateTime()->setTimezone(new DateTimeZone('Asia/Manila')) : null;
    $durationStr = 'N/A';

    if ($timeOut) {
        $diff = $timeOut->getTimestamp() - $timeIn->getTimestamp();
        $durationStr = gmdate('H\h i\m s\s', $diff);
    }

    $sheet->setCellValue('A' . $row, ($log['studentInfo']['first_name'] ?? '') . ' ' . ($log['studentInfo']['last_name'] ?? 'N/A'));
    $sheet->setCellValue('B' . $row, $log['student_no'] ?? 'N/A');
    $sheet->setCellValue('C' . $row, $log['studentInfo']['program'] ?? 'N/A');
    $sheet->setCellValue('D' . $row, $timeIn->format('M d, Y - h:i A'));
    $sheet->setCellValue('E' . $row, $timeOut ? $timeOut->format('h:i A') : 'In Library');
    $sheet->setCellValue('F' . $row, $durationStr);
    $sheet->setCellValue('G' . $row, $log['location'] ?? 'N/A');
    $row++;
}

// --- Auto-size columns for a clean look ---
foreach (range('A', 'G') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// --- Output to browser ---
$filename = 'FELMS_Attendance_Report_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;