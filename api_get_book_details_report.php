<?php
// api_get_book_details_report.php
session_start();
// Make sure to include the Composer autoloader for PhpSpreadsheet
require_once __DIR__ . '/vendor/autoload.php'; 
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

// Use statements for PhpSpreadsheet
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Helper function to fetch a book's description from Google Books API.
 */
function getBookDescriptionFromAPI(string $isbn): string {
    $apiUrl = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . urlencode($isbn);
    $responseJson = @file_get_contents($apiUrl);
    if ($responseJson === false) return "No description available.";
    $data = json_decode($responseJson, true);
    if (!empty($data['items'])) {
        foreach($data['items'] as $item) {
            if (!empty($item['volumeInfo']['description'])) {
                return $item['volumeInfo']['description'];
            }
        }
    }
    return "No description available.";
}

// Get ISBN and export flag from the URL
$isbn = $_GET['isbn'] ?? null;
$isExportRequest = isset($_GET['export']) && $_GET['export'] === 'excel';

if (!$isbn) {
    if ($isExportRequest) die("ISBN is required.");
    echo json_encode(['status' => 'error', 'message' => 'ISBN is required.']);
    exit;
}

try {
    $db = Database::getInstance();
    $borrowsCollection = $db->borrows();
    $booksCollection = $db->books();
    $studentsCollection = $db->students();
    $studentsCollectionName = $studentsCollection->getCollectionName();

    // --- 1. Get Base Book Details ---
    $book = $booksCollection->findOne(['isbn' => $isbn]);
    if (!$book) {
        if ($isExportRequest) die("Book not found.");
        echo json_encode(['status' => 'error', 'message' => 'Book not found.']);
        exit;
    }

    // --- 2. Get Borrowing Statistics (Total Count & Top Borrower) ---
    $pipeline = [
        ['$match' => ['isbn' => $isbn]],
        ['$group' => [
            '_id' => '$student_no',
            'borrowCount' => ['$sum' => 1]
        ]],
        ['$sort' => ['borrowCount' => -1]], // Sort to find top borrower
        ['$lookup' => [
            'from' => $studentsCollectionName,
            'localField' => '_id',
            'foreignField' => 'student_no',
            'as' => 'studentDetails'
        ]],
        ['$unwind' => ['path' => '$studentDetails', 'preserveNullAndEmptyArrays' => true]]
    ];
    $borrowStats = $borrowsCollection->aggregate($pipeline)->toArray();
    
    $totalBorrows = 0;
    $topBorrower = ['name' => 'N/A', 'count' => 0];
    $allBorrowers = [];

    foreach ($borrowStats as $stat) {
        $totalBorrows += $stat['borrowCount'];
        $studentName = formatName($stat['studentDetails'] ?? null);
        $allBorrowers[] = [
            'name' => $studentName,
            'student_no' => $stat['_id'],
            'count' => $stat['borrowCount']
        ];
    }
    
    if (!empty($allBorrowers)) {
        $topBorrower = [
            'name' => $allBorrowers[0]['name'],
            'count' => $allBorrowers[0]['count']
        ];
    }

    // --- 3. Handle the Request (Export or JSON) ---
    if ($isExportRequest) {
        // --- GENERATE PROFESSIONAL EXCEL REPORT ---
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // --- STYLING ---
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18);
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10);
        $sheet->getStyle('A4:C4')->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
        $sheet->getStyle('A4:C4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('4F81BD'); // Blue header
        $sheet->getStyle('C')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A4:C4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // --- PAGE SETUP & HEADERS ---
        $sheet->setTitle('Borrowing Report');
        $sheet->mergeCells('A1:C1');
        $sheet->setCellValue('A1', 'Book Borrowing Report: ' . ($book['title'] ?? 'N/A'));
        $sheet->mergeCells('A2:C2');
        $sheet->setCellValue('A2', 'Generated on: ' . date('Y-m-d H:i:s'));

        $sheet->setCellValue('A4', 'Student Name');
        $sheet->setCellValue('B4', 'Student Number');
        $sheet->setCellValue('C4', 'Times Borrowed');
        
        $sheet->getColumnDimension('A')->setWidth(35);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(20);
        
        // --- POPULATE DATA ---
        $rowIndex = 5;
        foreach ($allBorrowers as $borrower) {
            $sheet->setCellValue('A' . $rowIndex, $borrower['name']);
            $sheet->setCellValueExplicit('B' . $rowIndex, $borrower['student_no'], DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $rowIndex, $borrower['count']);
            $rowIndex++;
        }
        
        // --- ADD SUMMARY ROW ---
        $rowIndex++;
        $sheet->getStyle('B' . $rowIndex . ':C' . $rowIndex)->getFont()->setBold(true);
        $sheet->setCellValue('B' . $rowIndex, 'Total Borrows:');
        $sheet->setCellValue('C' . $rowIndex, $totalBorrows);

        // --- GENERATE AND SEND THE FILE ---
        $writer = new Xlsx($spreadsheet);
        $filename = 'Report_' . preg_replace("/[^a-zA-Z0-9]+/", "_", $book['title']) . '_' . date('Y-m-d') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $writer->save('php://output');
        exit();

    } else {
        // --- RETURN JSON FOR MODAL ---
        $description = getBookDescriptionFromAPI($isbn); // Fetch description
        
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'bookDetails' => [
                'title' => $book['title'],
                'thumbnail' => $book['thumbnail_url'] ?? 'https://placehold.co/128x192/f1f5f9/475569?text=N/A',
                'description' => $description
            ],
            'borrowCount' => $totalBorrows,
            'topBorrower' => $topBorrower,
            'allBorrowers' => $allBorrowers
        ]);
        exit();
    }

} catch (Exception $e) {
    if ($isExportRequest) die("Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>