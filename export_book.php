<?php
// export_book.php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
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
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

// 1. Get filter parameters from the URL
$selected_genre = $_GET['genre'] ?? 'all';
$search_term = trim($_GET['search'] ?? '');

// 2. Build the MongoDB query based on ALL filters
try {
    $dbInstance = Database::getInstance();
    $addBookCollection = $dbInstance->books();
    $match = [];
    if ($selected_genre !== 'all' && !empty($selected_genre)) {
        $match['genre'] = $selected_genre;
    }
    if (!empty($search_term)) {
        $match['$or'] = [
            ['title' => ['$regex' => $search_term, '$options' => 'i']],
            ['authors' => ['$regex' => $search_term, '$options' => 'i']],
            ['isbn' => ['$regex' => $search_term, '$options' => 'i']]
        ];
    }
    $pipeline = [];
    if (!empty($match)) {
        $pipeline[] = ['$match' => $match];
    }
    $pipeline[] = ['$sort' => ['title' => 1]];
    $books = iterator_to_array($addBookCollection->aggregate($pipeline));
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

// 3. Create and style the Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Book Collection');

// Set page orientation to Landscape for printing
$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);

// --- DEFINE PROFESSIONAL STYLES ---
$titleStyle = [
    'font' => ['bold' => true, 'size' => 20, 'color' => ['rgb' => '1E3A8A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
];
$dateStyle = [
    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '4B5563']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
];
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A8A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
];
$cellBorders = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'D1D5DB'],
        ],
    ],
];
$evenRowStyle = [ 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']] ];

// --- APPLY TITLE AND DATE ---
$sheet->mergeCells('A1:K1');
$sheet->setCellValue('A1', 'Book Collection List');
$sheet->getStyle('A1')->applyFromArray($titleStyle);
$sheet->getRowDimension('1')->setRowHeight(30);

$sheet->mergeCells('A2:K2');
$sheet->setCellValue('A2', 'Report Generated on: ' . date('F j, Y, g:i A'));
$sheet->getStyle('A2')->applyFromArray($dateStyle);
$sheet->getRowDimension('2')->setRowHeight(20);

// --- APPLY HEADERS ---
$headers = ['Title', 'Authors', 'ISBN', 'Publisher', 'Published Date', 'Genre', 'Quantity', 'Status', 'Language', 'Page Count', 'Description'];
$sheet->fromArray($headers, NULL, 'A4');
$sheet->getStyle('A4:K4')->applyFromArray($headerStyle);
$sheet->getRowDimension('4')->setRowHeight(22);

// --- POPULATE DATA ROWS WITH STYLING ---
$row = 5;
foreach ($books as $book) {
    $authorsValue = $book['authors'] ?? 'N/A';
    if ($authorsValue instanceof \MongoDB\Model\BSONArray) {
        $authorsValue = implode(', ', iterator_to_array($authorsValue));
    }

    // ✨ **FIX: This section processes the description**
    $description = $book['description'] ?? 'N/A';
    // Remove all line breaks
    $description = str_replace(["\r", "\n"], ' ', $description);
    // Shorten the text if it's too long
    if (strlen($description) > 250) {
        $description = substr($description, 0, 250) . '...';
    }

    $sheet->setCellValue('A' . $row, $book['title'] ?? 'N/A');
    $sheet->setCellValue('B' . $row, $authorsValue);
    $sheet->setCellValueExplicit('C' . $row, $book['isbn'] ?? 'N/A', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING); // Force ISBN as text
    $sheet->setCellValue('D' . $row, $book['publisher'] ?? 'N/A');
    $sheet->setCellValue('E' . $row, $book['published_date'] ?? 'N/A');
    $sheet->setCellValue('F' . $row, $book['genre'] ?? 'N/A');
    $sheet->setCellValue('G' . $row, $book['quantity'] ?? 'N/A');
    $sheet->setCellValue('H' . $row, $book['status'] ?? 'N/A');
    $sheet->setCellValue('I' . $row, $book['language'] ?? 'N/A');
    $sheet->setCellValue('J' . $row, $book['page_count'] ?? 'N/A');
    $sheet->setCellValue('K' . $row, $description); // Use the processed description

    if ($row % 2 == 0) {
        $sheet->getStyle('A' . $row . ':K' . $row)->applyFromArray($evenRowStyle);
    }
    $row++;
}

// --- FINAL FORMATTING ---
$lastRow = $row - 1;
$sheet->getStyle('A4:K' . $lastRow)->applyFromArray($cellBorders);
$sheet->getStyle('A5:K' . $lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER); // Center text vertically in rows


// Auto-size columns for a clean look
foreach (range('A', 'J') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
// Set a fixed, wide width for the description column
$sheet->getColumnDimension('K')->setWidth(80);


// 4. Output the file to the browser
$filename = 'FELMS_Book_Collection_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;