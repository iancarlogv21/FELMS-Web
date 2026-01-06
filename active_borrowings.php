<?php
$currentPage = 'active_borrowings';
session_start();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php'; // Assuming getStudentPhotoUrl() is in here

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// Use statements for the PhpSpreadsheet library classes
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// Helper function to fetch book thumbnails if they are missing
function getBookThumbnailFromAPI(string $isbn): string {
    $placeholder = 'https://placehold.co/80x120/f1f5f9/475569?text=N/A';
    if (empty($isbn)) return $placeholder;
    $apiUrl = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . urlencode($isbn);
    $responseJson = @file_get_contents($apiUrl);
    if ($responseJson === false) return $placeholder;
    $data = json_decode($responseJson);
    return $data->items[0]->volumeInfo->imageLinks->thumbnail ?? $placeholder;
}

// --- PAGE SETUP & QUERY ---
$reportTitle = 'Active Borrowings Report';
$pageTitle = $reportTitle . ' - FELMS';
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$report_data = [];
$db_error = null;

try {
    // Assuming Database::getInstance() and borrows() methods are correctly implemented
    $dbInstance = Database::getInstance();
    $borrowCollection = $dbInstance->borrows();
    
    // QUERY: Finds all books that have not been returned yet.
    $filter = ['return_date' => null];
    
    $pipeline = [
        ['$match' => $filter],
        ['$sort' => ['due_date' => 1]], // Sort by due date to see which are due soonest
        ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
        ['$lookup' => ['from' => 'AddBook', 'localField' => 'isbn', 'foreignField' => 'isbn', 'as' => 'book_details']],
        ['$unwind' => ['path' => '$student_details', 'preserveNullAndEmptyArrays' => true]],
        ['$unwind' => ['path' => '$book_details', 'preserveNullAndEmptyArrays' => true]]
    ];
    
    $cursor = $borrowCollection->aggregate($pipeline);
    $report_data = iterator_to_array($cursor);

} catch (Exception $e) {
    $db_error = "Could not fetch report data: " . $e->getMessage();
}

// --- Calculate Summary Totals & Collect Filter Data ---
$total_records = count($report_data);
$total_overdue_count = 0;
$unique_titles = ['All Books']; // Initialize for filter dropdown

foreach ($report_data as $key => $record) {
    try {
        $dueDate = new DateTimeImmutable($record['due_date']);
        if ($today > $dueDate) {
            $total_overdue_count++;
            $report_data[$key]['status_label'] = 'Overdue';
        } else {
             $report_data[$key]['status_label'] = 'On-Time';
        }
    } catch (Exception $e) {
         $report_data[$key]['status_label'] = 'N/A';
    }
    
    // Collect unique titles
    $title = $record['book_details']['title'] ?? 'Book Not Found';
    if (!in_array($title, $unique_titles)) {
        $unique_titles[] = $title;
    }
}
sort($unique_titles); // Alphabetical sort for titles

// --- NEW: Handle Professional Excel Export ---
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $penaltyRate = 10; 

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Active Borrows');
    $lastColumn = 'F'; // The final column used in the report

    // --- 1. TITLE & METADATA ---
    $sheet->mergeCells("A1:{$lastColumn}1");
    $sheet->setCellValue('A1', $reportTitle);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('333333'));
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells("A2:{$lastColumn}2");
    $sheet->setCellValue('A2', 'Generated on: ' . $today->format('Y-m-d H:i:s'));
    $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('666666'));
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $currentRow = 4; // Start data below header/metadata

    // --- 2. SUMMARY TOTALS ---
    $summaryStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => '1F497D']], // Dark blue text
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DEEAF6']], // Light blue fill
    ];

    $sheet->setCellValue('A' . $currentRow, 'Total Active Borrows:');
    $sheet->setCellValue('B' . $currentRow, $total_records);
    $sheet->getStyle('A' . $currentRow . ':B' . $currentRow)->applyFromArray($summaryStyle);
    $sheet->getStyle('B' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');

    $currentRow++;
    $sheet->setCellValue('A' . $currentRow, 'Books Currently Overdue:');
    $sheet->setCellValue('B' . $currentRow, $total_overdue_count);
    $sheet->getStyle('A' . $currentRow . ':B' . $currentRow)->applyFromArray($summaryStyle);
    $sheet->getStyle('B' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');

    $currentRow += 2; // Two blank rows after summary

    // --- 3. TABLE HEADERS (The Red/Dark Header from Overdue Example) ---
    $headerRow = $currentRow;
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC3545']], // Red color for attention
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
    ];
    $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray($headerStyle);
    
    $sheet->setCellValue('A' . $headerRow, 'Book Title');
    $sheet->setCellValue('B' . $headerRow, 'Student Name');
    $sheet->setCellValue('C' . $headerRow, 'Borrow Date');
    $sheet->setCellValue('D' . $headerRow, 'Due Date');
    $sheet->setCellValue('E' . $headerRow, 'Status');
    $sheet->setCellValue('F' . $headerRow, 'Current Penalty (₱)');
    
    // --- SET COLUMN WIDTHS ---
    $sheet->getColumnDimension('A')->setWidth(40);
    $sheet->getColumnDimension('B')->setWidth(30);
    $sheet->getColumnDimension('C')->setWidth(18);
    $sheet->getColumnDimension('D')->setWidth(18);
    $sheet->getColumnDimension('E')->setWidth(15);
    $sheet->getColumnDimension('F')->setWidth(20);

    $currentRow++; // Move to data start row

    // --- 4. DATA ROWS ---
    foreach ($report_data as $record) {
        $penalty = 0;
        $status = 'On-Time';
        $statusColor = '008000'; // Green

        try {
            $dueDate = new DateTimeImmutable($record['due_date']);
            if ($today > $dueDate) {
                $interval = $today->diff($dueDate);
                $penalty = (int)$interval->format('%a') * $penaltyRate;
                $status = 'Overdue';
                $statusColor = 'FF0000'; // Red
            }
        } catch(Exception $e) {}

        // Populate cells
        $sheet->setCellValue('A' . $currentRow, $record['book_details']['title'] ?? 'N/A');
        $sheet->setCellValue('B' . $currentRow, ($record['student_details']['first_name'] ?? 'Student') . ' ' . ($record['student_details']['last_name'] ?? 'Not Found'));
        $sheet->setCellValue('C' . $currentRow, $record['borrow_date'] ?? '');
        $sheet->setCellValue('D' . $currentRow, $record['due_date'] ?? '');
        $sheet->setCellValue('E' . $currentRow, $status);
        $sheet->setCellValue('F' . $currentRow, $penalty);

        // Apply conditional styling for Status and Penalty
        $sheet->getStyle('E' . $currentRow)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($statusColor))->setBold(true);
        $sheet->getStyle('F' . $currentRow)->getNumberFormat()->setFormatCode('₱#,##0.00');
        $sheet->getStyle('F' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        
        $currentRow++;
    }

    // --- GENERATE AND SEND THE FILE ---
    $writer = new Xlsx($spreadsheet);
    $filename = 'Active_Borrowings_Report_' . date('Y-m-d') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit();
}

require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/sidebar.php';
?>

<main id="main-content" class="flex-1 p-6 md:p-10">
    <header class="flex flex-col md:flex-row justify-between md:items-center mb-8">
        <div>
            <h1 class="text-4xl font-bold tracking-tight"><?= htmlspecialchars($reportTitle) ?></h1>
            <p class="text-secondary mt-2">A list of all books that are currently checked out.</p>
        </div>
        <a href="?export=excel" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-green-600 rounded-lg shadow-sm hover:bg-green-700 hover:shadow-md hover:-translate-y-px transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
    <i data-lucide="file-spreadsheet" class="w-4 h-4"></i>
    <span>Export to Excel</span>
</a>
    </header>

    <?php if ($db_error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
            <p><?= htmlspecialchars($db_error) ?></p>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <div class="bg-card p-6 rounded-xl border border-theme flex items-center gap-4 shadow-sm">
            <div class="bg-blue-100 p-3 rounded-full"><i data-lucide="list" class="w-7 h-7 text-blue-600"></i></div>
            <div>
                <p class="text-secondary">Total Active Borrows</p>
                <p class="text-3xl font-bold"><?php echo number_format($total_records); ?></p>
            </div>
        </div>
        <div class="bg-card p-6 rounded-xl border border-theme flex items-center gap-4 shadow-sm">
            <div class="bg-yellow-100 p-3 rounded-full"><i data-lucide="alert-triangle" class="w-7 h-7 text-yellow-600"></i></div>
            <div>
                <p class="text-secondary">Books Currently Overdue</p>
                <p class="text-3xl font-bold"><?php echo number_format($total_overdue_count); ?></p>
            </div>
        </div>
    </div>
    
    <div class="flex flex-col md:flex-row gap-4 mb-6">
        <div class="relative flex-1">
            <input type="text" id="searchInput" placeholder="Search by Book Title or Student Name..." class="w-full pl-10 pr-4 py-2 border border-theme rounded-lg focus:ring-2 focus:ring-primary focus:border-primary bg-card" onkeyup="filterTable()">
            <i data-lucide="search" class="w-5 h-5 absolute left-3 top-1/2 transform -translate-y-1/2 text-secondary"></i>
        </div>

        <select id="statusFilter" class="w-full md:w-1/4 py-2 px-4 border border-theme rounded-lg focus:ring-2 focus:ring-primary focus:border-primary bg-card" onchange="filterTable()">
            <option value="All">Filter by Status (All)</option>
            <option value="On-Time">On-Time</option>
            <option value="Overdue">Overdue</option>
        </select>
        
        <select id="titleFilter" class="w-full md:w-1/4 py-2 px-4 border border-theme rounded-lg focus:ring-2 focus:ring-primary focus:border-primary bg-card" onchange="filterTable()">
            <option value="All Books">Filter by Book Title (All)</option>
            <?php foreach ($unique_titles as $title): ?>
                <option value="<?= htmlspecialchars($title) ?>"><?= htmlspecialchars($title) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="bg-card rounded-lg border border-theme shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left" id="borrowingsTable">
                <thead class="text-xs text-secondary uppercase bg-body sticky top-0">
                    <tr>
                        <th class="px-6 py-4">Book & Student</th>
                        <th class="px-6 py-4">Dates</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Current Penalty</th>
                    </tr>
                </thead>
                <tbody class="bg-card">
                    <?php if (empty($report_data) && $db_error === null): ?>
                        <tr id="initial-no-data-row"><td colspan="4" class="text-center py-12 text-secondary">No active borrowings found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($report_data as $record): ?>
                            <?php
                                $isOverdue = $record['status_label'] === 'Overdue';
                                $penalty = 0;
                                try {
                                    $dueDate = new DateTimeImmutable($record['due_date']);
                                    if ($today > $dueDate) {
                                        $interval = $today->diff($dueDate);
                                        $penalty = (int)$interval->format('%a') * 10;
                                    }
                                } catch (Exception $e) {}
                                
                                $bookTitle = $record['book_details']['title'] ?? 'Book Not Found';
                                $studentName = ($record['student_details']['first_name'] ?? '') . ' ' . ($record['student_details']['last_name'] ?? 'Not Found');
                            ?>
                            <tr class="border-b border-theme hover:bg-body" 
                                data-book-title="<?= htmlspecialchars($bookTitle) ?>" 
                                data-status="<?= htmlspecialchars($record['status_label']) ?>"
                                data-search-term="<?= htmlspecialchars(strtolower($bookTitle . ' ' . $studentName)) ?>"
                            >
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-4">
                                        <img src="<?= htmlspecialchars($record['book_details']['thumbnail'] ?? getBookThumbnailFromAPI($record['isbn'] ?? '')) ?>" alt="Book Cover" class="w-12 h-16 object-cover rounded-md flex-shrink-0">
                                        <div>
                                            <div class="font-bold text-base">
    <?= htmlspecialchars($bookTitle) ?>
</div>
                                            <div class="flex items-center gap-2 mt-1">
                                                <img src="<?= htmlspecialchars(getStudentPhotoUrl($record['student_details'] ?? null)) ?>" alt="Student" class="w-6 h-6 rounded-full object-cover">
                                                <span class="text-xs text-secondary">
                                                    <?= htmlspecialchars($studentName) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-xs">
                                    <div><strong>Borrowed:</strong> <?= !empty($record['borrow_date']) ? (new DateTime($record['borrow_date']))->format('M d, Y') : 'N/A' ?></div>
                                    <div class="font-medium <?= $isOverdue ? 'text-red-600' : 'text-secondary' ?> mt-1">
                                        <strong>Due:</strong> <?= !empty($record['due_date']) ? (new DateTime($record['due_date']))->format('M d, Y') : 'N/A' ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($isOverdue): ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">Overdue</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">On-Time</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 font-bold text-lg <?= $penalty > 0 ? 'text-orange-600' : 'text-green-600' ?>">
                                    ₱<?= number_format($penalty, 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php
require_once __DIR__ . '/templates/footer.php';
?>

<script>
    function filterTable() {
        // Get the values from the search bar and filter dropdowns
        const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
        const statusFilter = document.getElementById('statusFilter').value;
        const titleFilter = document.getElementById('titleFilter').value;
        const tableBody = document.querySelector('#borrowingsTable tbody');
        const rows = tableBody.querySelectorAll('tr');

        let visibleRowCount = 0;
        const noDataRowId = 'no-matching-data-row';
        const initialNoDataRowId = 'initial-no-data-row';

        // Remove the initial "No active borrowings found" message if it exists
        const initialNoDataRow = document.getElementById(initialNoDataRowId);
        if (initialNoDataRow) {
            initialNoDataRow.style.display = 'none';
        }

        rows.forEach(row => {
            // Skip the dynamic no-data row if it exists
            if (row.id === noDataRowId || row.id === initialNoDataRowId) {
                return;
            }

            // Get the data attributes for comparison
            const rowSearchTerm = row.getAttribute('data-search-term') || '';
            const rowStatus = row.getAttribute('data-status') || '';
            const rowTitle = row.getAttribute('data-book-title') || '';

            // 1. Search Filter (Book Title or Student Name)
            const passesSearch = rowSearchTerm.includes(searchTerm);

            // 2. Status Filter
            const passesStatus = statusFilter === 'All' || rowStatus === statusFilter;

            // 3. Title Filter
            const passesTitle = titleFilter === 'All Books' || rowTitle === titleFilter;

            // Show or hide the row based on all filters
            if (passesSearch && passesStatus && passesTitle) {
                row.style.display = ''; // Show the row
                visibleRowCount++;
            } else {
                row.style.display = 'none'; // Hide the row
            }
        });

        // Handle the dynamic "No matching data" message
        let noDataRow = document.getElementById(noDataRowId);
        
        if (visibleRowCount === 0) {
            if (!noDataRow) {
                // Create the 'No Matching Data' row
                noDataRow = document.createElement('tr');
                noDataRow.id = noDataRowId;
                noDataRow.innerHTML = `<td colspan="4" class="text-center py-12 text-secondary">No matching active borrowings found.</td>`;
                tableBody.appendChild(noDataRow);
            } else {
                // If it exists, ensure it's visible
                noDataRow.style.display = '';
            }
        } else {
            // If data is visible, hide/remove the no-data row
            if (noDataRow) {
                noDataRow.style.display = 'none';
            }
        }
    }

    // Call the filterTable function once on page load to ensure initial state is correct (optional, but good practice)
    document.addEventListener('DOMContentLoaded', filterTable); 
</script>