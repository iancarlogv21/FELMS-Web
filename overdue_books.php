<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- SET THE CURRENT PAGE FOR THE SIDEBAR ---
$currentPage = 'overdue_books'; // Matches sidebar.php
session_start();
// Include the Composer autoloader to load the library
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php'; // Assuming formatMongoDate() and getStudentPhotoUrl() are here

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
$reportTitle = 'Overdue Books Report';
$pageTitle = $reportTitle . ' - FELMS';
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$penaltyRate = 10; // Penalty rate per day
$report_data = [];
$db_error = null;
$unique_titles = ['All Books']; // For filter dropdown

try {
    $dbInstance = Database::getInstance();
    $borrowCollection = $dbInstance->borrows();
    
    // QUERY: Finds all books that have not been returned AND are past their due date.
    $filter = [
        'return_date' => null,
        // Using $today minus one second to ensure books due today (start of day) are not counted yet
        'due_date' => ['$lt' => $today->format('Y-m-d H:i:s')] 
    ];
    
    $pipeline = [
        ['$match' => $filter],
        ['$sort' => ['due_date' => 1]], // Sort by due date, most overdue first
        ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
        ['$lookup' => ['from' => 'AddBook', 'localField' => 'isbn', 'foreignField' => 'isbn', 'as' => 'book_details']],
        ['$unwind' => ['path' => '$student_details', 'preserveNullAndEmptyArrays' => true]],
        ['$unwind' => ['path' => '$book_details', 'preserveNullAndEmptyArrays' => true]]
    ];
    
    $cursor = $borrowCollection->aggregate($pipeline);
    
    // Pre-calculate penalties, days overdue, and collect filter data ONCE
    foreach ($cursor as $record) {
        $daysOverdue = 0;
        $penalty = 0;
        try {
            $dueDate = new DateTimeImmutable($record['due_date']);
            // Recalculate days overdue
            if ($today > $dueDate) {
                // The diff object's format('%a') returns the total number of days
                $daysOverdue = (int)$today->diff($dueDate)->format('%a');
                $penalty = $daysOverdue * $penaltyRate;
            }
        } catch (Exception $e) {}

        $record['days_overdue'] = $daysOverdue;
        $record['calculated_penalty'] = $penalty;
        
        // Collect unique titles for filter
        $title = $record['book_details']['title'] ?? 'Book Not Found';
        if (!in_array($title, $unique_titles)) {
            $unique_titles[] = $title;
        }

        $report_data[] = $record;
    }
    sort($unique_titles);

} catch (Exception $e) {
    $db_error = "Could not fetch report data: " . $e->getMessage();
}

// --- Calculate Summary Totals (now much cleaner) ---
$total_records = count($report_data);
$total_penalty_sum = array_sum(array_column($report_data, 'calculated_penalty'));

// --- Handle Professional Excel Export ---
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Overdue Books');
    $lastColumn = 'G'; // The final column used in the report

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

    $sheet->setCellValue('A' . $currentRow, 'Total Overdue Books:');
    $sheet->setCellValue('B' . $currentRow, $total_records);
    $sheet->getStyle('A' . $currentRow . ':B' . $currentRow)->applyFromArray($summaryStyle);
    $sheet->getStyle('B' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');

    $currentRow++;
    $sheet->setCellValue('A' . $currentRow, 'Sum of Current Penalties:');
    $sheet->setCellValue('B' . $currentRow, $total_penalty_sum);
    $sheet->getStyle('A' . $currentRow . ':B' . $currentRow)->applyFromArray($summaryStyle);
    $sheet->getStyle('B' . $currentRow)->getNumberFormat()->setFormatCode('₱#,##0.00');

    $currentRow += 2; // Two blank rows after summary

    // --- 3. TABLE HEADERS (Red color) ---
    $headerRow = $currentRow;
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC3545']], // Red color for attention
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
    ];
    $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray($headerStyle);
    
    $sheet->setCellValue('A' . $headerRow, 'Book Title');
    $sheet->setCellValue('B' . $headerRow, 'Student Name');
    $sheet->setCellValue('C' . $headerRow, 'Student No');
    $sheet->setCellValue('D' . $headerRow, 'Borrow Date');
    $sheet->setCellValue('E' . $headerRow, 'Due Date');
    $sheet->setCellValue('F' . $headerRow, 'Days Overdue');
    $sheet->setCellValue('G' . $headerRow, 'Current Penalty (₱)');
    
    // --- SET COLUMN WIDTHS ---
    $sheet->getColumnDimension('A')->setWidth(40);
    $sheet->getColumnDimension('B')->setWidth(30);
    $sheet->getColumnDimension('C')->setWidth(18);
    $sheet->getColumnDimension('D')->setWidth(18);
    $sheet->getColumnDimension('E')->setWidth(18);
    $sheet->getColumnDimension('F')->setWidth(15);
    $sheet->getColumnDimension('G')->setWidth(20);

    $currentRow++; // Move to data start row

    // --- 4. DATA ROWS ---
    foreach ($report_data as $record) {
        // Populate cells using pre-calculated values
        $sheet->setCellValue('A' . $currentRow, $record['book_details']['title'] ?? 'N/A');
        
        // Use the 'Firstname Lastname' format you requested
        $sheet->setCellValue('B' . $currentRow, ($record['student_details']['first_name'] ?? 'Student') . ' ' . ($record['student_details']['last_name'] ?? 'Not Found'));

        $sheet->setCellValueExplicit('C' . $currentRow, $record['student_details']['student_no'] ?? 'N/A', DataType::TYPE_STRING);
        $sheet->setCellValue('D' . $currentRow, formatMongoDate($record['borrow_date']));
        $sheet->setCellValue('E' . $currentRow, formatMongoDate($record['due_date']));
        $sheet->setCellValue('F' . $currentRow, $record['days_overdue']);
        $sheet->setCellValue('G' . $currentRow, $record['calculated_penalty']);

        // Apply conditional styling for Status and Penalty
        $sheet->getStyle('F' . $currentRow)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0000'))->setBold(true); // Red days
        $sheet->getStyle('F' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('G' . $currentRow)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0000'))->setBold(true); // Red penalty
        $sheet->getStyle('G' . $currentRow)->getNumberFormat()->setFormatCode('₱#,##0.00');
        $sheet->getStyle('G' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        
        $currentRow++;
    }
    
    // --- 5. ADD TOTALS AT THE BOTTOM ---
    $currentRow++; // Add a blank row
    $sheet->getStyle('F' . $currentRow . ':G' . $currentRow)->getFont()->setBold(true);
    $sheet->setCellValue('F' . $currentRow, 'Total Penalties:');
    $sheet->setCellValue('G' . $currentRow, $total_penalty_sum);
    
    $sheet->getStyle('G' . $currentRow)->getNumberFormat()->setFormatCode('₱#,##0.00');
    $sheet->getStyle('G' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);


    // --- GENERATE AND SEND THE FILE ---
    $writer = new Xlsx($spreadsheet);
    $filename = 'Overdue_Books_Report_' . date('Y-m-d') . '.xlsx';

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
            <p class="text-secondary mt-2">A list of all books that are currently overdue.</p>
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
            <div class="bg-yellow-100 p-3 rounded-full"><i data-lucide="alert-triangle" class="w-7 h-7 text-yellow-600"></i></div>
            <div>
                <p class="text-secondary">Total Overdue Books</p>
                <p class="text-3xl font-bold"><?php echo number_format($total_records); ?></p>
            </div>
        </div>
        <div class="bg-card p-6 rounded-xl border border-theme flex items-center gap-4 shadow-sm">
            <div class="bg-orange-100 p-3 rounded-full"><i data-lucide="coins" class="w-7 h-7 text-orange-600"></i></div>
            <div>
                <p class="text-secondary">Sum of Current Penalties</p>
                <p class="text-3xl font-bold">₱<?php echo number_format($total_penalty_sum, 2); ?></p>
            </div>
        </div>
    </div>

    <div class="flex flex-col md:flex-row gap-4 mb-6">
        <div class="relative flex-1">
            <input type="text" id="searchInput" placeholder="Search by Book Title or Student Name..." class="w-full pl-10 pr-4 py-2 border border-theme rounded-lg focus:ring-2 focus:ring-primary focus:border-primary bg-card" onkeyup="filterTable()">
            <i data-lucide="search" class="w-5 h-5 absolute left-3 top-1/2 transform -translate-y-1/2 text-secondary"></i>
        </div>

        <select id="titleFilter" class="w-full md:w-1/4 py-2 px-4 border border-theme rounded-lg focus:ring-2 focus:ring-primary focus:border-primary bg-card" onchange="filterTable()">
            <option value="All Books">Filter by Book Title (All)</option>
            <?php foreach ($unique_titles as $title): ?>
                <option value="<?= htmlspecialchars($title) ?>"><?= htmlspecialchars($title) ?></option>
            <?php endforeach; ?>
        </select>

        <select id="daysFilter" class="w-full md:w-1/4 py-2 px-4 border border-theme rounded-lg focus:ring-2 focus:ring-primary focus:border-primary bg-card" onchange="filterTable()">
            <option value="All">Filter by Days Overdue</option>
            <option value="1-7">1 - 7 Days</option>
            <option value="8-14">8 - 14 Days</option>
            <option value="15-30">15 - 30 Days</option>
            <option value="30+">Over 30 Days</option>
        </select>
    </div>
    <div class="bg-card rounded-lg border border-theme shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left" id="overdueTable">
                <thead class="text-xs text-secondary uppercase bg-body sticky top-0">
                    <tr>
                        <th class="px-6 py-4">Book & Student</th>
                        <th class="px-6 py-4">Dates</th>
                        <th class="px-6 py-4">Days Overdue</th>
                        <th class="px-6 py-4">Current Penalty</th>
                    </tr>
                </thead>
                <tbody class="bg-card">
                    <?php if (empty($report_data)): ?>
                        <tr id="initial-no-data-row"><td colspan="4" class="text-center py-12 text-secondary">🎉 No overdue books found. Great job!</td></tr>
                    <?php else: ?>
                        <?php foreach ($report_data as $record): ?>
                            <?php
                                $bookTitle = $record['book_details']['title'] ?? 'Book Not Found';
                                $studentName = ($record['student_details']['first_name'] ?? 'Student') . ' ' . ($record['student_details']['last_name'] ?? 'Not Found');
                                $daysOverdue = $record['days_overdue'];
                            ?>
                            <tr class="border-b border-theme hover:bg-body" 
                                data-book-title="<?= htmlspecialchars($bookTitle) ?>" 
                                data-days-overdue="<?= $daysOverdue ?>"
                                data-search-term="<?= htmlspecialchars(strtolower($bookTitle . ' ' . $studentName)) ?>"
                            >
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-4">
                                        <img src="<?= htmlspecialchars($record['book_details']['thumbnail'] ?? getBookThumbnailFromAPI($record['isbn'] ?? '')) ?>" alt="Book Cover" class="w-12 h-16 object-cover rounded-md flex-shrink-0">
                                        <div>
                                            <div class="font-bold text-base"><?= htmlspecialchars($bookTitle) ?></div>
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
                                    <div><strong>Borrowed:</strong> <?= formatMongoDate($record['borrow_date'], 'M d, Y') ?></div>
                                    <div class="font-medium text-red-600 mt-1"><strong>Due:</strong> <?= formatMongoDate($record['due_date'], 'M d, Y') ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="font-bold text-xl text-red-600"><?= $daysOverdue ?></span>
                                    <span class="text-secondary">days</span>
                                </td>
                                <td class="px-6 py-4 font-bold text-lg text-orange-600">
                                    ₱<?= number_format($record['calculated_penalty'], 2) ?>
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
        // Get filter values
        const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
        const titleFilter = document.getElementById('titleFilter').value;
        const daysFilter = document.getElementById('daysFilter').value;
        
        const tableBody = document.querySelector('#overdueTable tbody');
        const rows = tableBody.querySelectorAll('tr');

        let visibleRowCount = 0;
        const noDataRowId = 'no-matching-data-row';
        const initialNoDataRowId = 'initial-no-data-row';

        // Helper function to check if a day count falls within a range string (e.g., '8-14')
        function checkDaysRange(days, rangeString) {
            if (rangeString === 'All') return true;
            if (rangeString === '30+') return days > 30;

            const [minStr, maxStr] = rangeString.split('-');
            const min = parseInt(minStr);
            const max = parseInt(maxStr);

            return days >= min && days <= max;
        }

        // Remove the initial "No overdue books found" message if it exists
        const initialNoDataRow = document.getElementById(initialNoDataRowId);
        if (initialNoDataRow) {
            initialNoDataRow.style.display = 'none';
        }

        rows.forEach(row => {
            // Skip dynamic/initial no-data rows
            if (row.id === noDataRowId || row.id === initialNoDataRowId) {
                return;
            }

            // Get data attributes
            const rowSearchTerm = row.getAttribute('data-search-term') || '';
            const rowTitle = row.getAttribute('data-book-title') || '';
            const rowDays = parseInt(row.getAttribute('data-days-overdue'));

            // 1. Search Filter (Book Title or Student Name)
            const passesSearch = rowSearchTerm.includes(searchTerm);

            // 2. Title Filter
            const passesTitle = titleFilter === 'All Books' || rowTitle === titleFilter;
            
            // 3. Days Overdue Filter
            const passesDays = checkDaysRange(rowDays, daysFilter);


            // Show or hide the row based on all filters
            if (passesSearch && passesTitle && passesDays) {
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
                noDataRow.innerHTML = `<td colspan="4" class="text-center py-12 text-secondary">No matching overdue books found.</td>`;
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

    // Run the filter on load to ensure the table displays correctly
    document.addEventListener('DOMContentLoaded', filterTable); 
</script>