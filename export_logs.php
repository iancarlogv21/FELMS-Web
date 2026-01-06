<?php
session_start();
// The database file is required to connect and fetch data
require_once __DIR__ . '/config/db.php'; 
// !!! REQUIRE THE PHPSPREADSHEET AUTOLOADER !!!
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Basic security check
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header('Location: login.php');
    exit;
}

// 1. Fetch ALL activity logs
$logs = [];
try {
    $db = Database::getInstance();
    $logsCollection = $db->activity_logs();
    $options = ['sort' => ['timestamp' => -1]]; 
    $logs = iterator_to_array($logsCollection->find([], $options));
} catch (Exception $e) {
    error_log("Export failed: " . $e->getMessage());
    die("Error fetching logs for export. Please check server logs.");
}

// 2. Initialize PhpSpreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$exportTime = new DateTime('now', new DateTimeZone('Asia/Manila'));

// --- START REPORT DESIGN ---

// A. Title Report
$sheet->setCellValue('A1', 'SYSTEM ACTIVITY LOG REPORT');
$sheet->mergeCells('A1:D1');
$sheet->getStyle('A1')->applyFromArray([
    'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '007BFF']], // Blue Color
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
]);

// B. Date Exported
$sheet->setCellValue('A3', 'Date Exported (Asia/Manila):');
$sheet->setCellValue('B3', $exportTime->format('Y-m-d h:i:s A'));
$sheet->getStyle('A3')->getFont()->setBold(true);

// C. Data Headers
$headers = ['Timestamp (Manila Time)', 'User', 'Action', 'Details'];
$headerRow = 5; // Start data at row 5
$col = 'A';

// Write headers
foreach ($headers as $header) {
    $sheet->setCellValue($col . $headerRow, $header);
    $col++;
}

// Apply Header Styling (Color, Bold, Border)
$headerRange = 'A' . $headerRow . ':' . chr(ord('A') + count($headers) - 1) . $headerRow;
$sheet->getStyle($headerRange)->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], // White text
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '10B981']], // Green-500 background
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '6EE7B7']]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
]);

// D. Populate Data
$row = $headerRow + 1;
foreach ($logs as $log) {
    $timestampFormatted = 'N/A';
    if (isset($log['timestamp'])) {
        try {
            $timestamp = $log['timestamp']->toDateTime()->setTimezone(new DateTimeZone('Asia/Manila'));
            $timestampFormatted = $timestamp->format('Y-m-d H:i:s A');
        } catch (Exception $e) {
            // Keep default 'N/A'
        }
    }

    $data = [
        $timestampFormatted,
        $log['username'] ?? 'N/A',
        str_replace('_', ' ', $log['action'] ?? 'N/A'),
        $log['details'] ?? '',
    ];

    $sheet->fromArray($data, NULL, 'A' . $row);
    
    $row++;
}

// E. Auto-size columns for readability
foreach (range('A', $sheet->getHighestColumn()) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// --- END REPORT DESIGN ---

// 3. Set headers for file download (.xlsx format)
$filename = 'System_Activity_Log_' . $exportTime->format('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Cache-Control: max-age=1');
header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT'); 

// 4. Output the XLSX file
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;