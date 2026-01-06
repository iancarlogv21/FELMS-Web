<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- SET THE CURRENT PAGE FOR THE SIDEBAR ---
$currentPage = 'penalty_report';

session_start();
// --- Add Composer's Autoloader ---
require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

// --- Import PhpSpreadsheet classes ---
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// If the user is not logged in, redirect to the login page
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

function getBookThumbnailFromAPI(string $isbn): string {
    $placeholder = 'https://placehold.co/80x120/f1f5f9/475569?text=N/A';
    if (empty($isbn)) return $placeholder;
    $apiUrl = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . urlencode($isbn);
    $responseJson = @file_get_contents($apiUrl);
    if ($responseJson === false) return $placeholder;
    $data = json_decode($responseJson);
    return $data->items[0]->volumeInfo->imageLinks->thumbnail ?? $placeholder;
}

// --- PHP LOGIC for fetching data ---
$reportTitle = 'Total Penalties Report';
$pageTitle = $reportTitle . ' - FELMS';
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$penaltyRate = 10;
$report_data = [];
$db_error = null;
$unique_titles = ['All Books']; // For filter dropdown

try {
    $dbInstance = Database::getInstance();
    $borrowCollection = $dbInstance->borrows();
    
    // Filter records that either have a stored penalty OR are currently overdue
    $filter = [
        '$or' => [
            ['penalty' => ['$gte' => $penaltyRate]],
            ['return_date' => null, 'due_date' => ['$lt' => $today->format('Y-m-d')]]
        ]
    ];
    
    $pipeline = [
        ['$match' => $filter],
        ['$sort' => ['due_date' => 1]],
        ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
        ['$lookup' => ['from' => 'AddBook', 'localField' => 'isbn', 'foreignField' => 'isbn', 'as' => 'book_details']],
        ['$unwind' => ['path' => '$student_details', 'preserveNullAndEmptyArrays' => true]],
        ['$unwind' => ['path' => '$book_details', 'preserveNullAndEmptyArrays' => true]]
    ];
    
    $cursor = $borrowCollection->aggregate($pipeline);
    
    foreach ($cursor as $record) {
        $penalty = 0;
        $isReturned = !empty($record['return_date']);
        
        if ($isReturned) {
            $penalty = $record['penalty'] ?? 0;
        } else {
            // Calculate current penalty for active, overdue borrowings
            try {
                $dueDate = new DateTimeImmutable($record['due_date']);
                if ($today > $dueDate) {
                    $interval = $today->diff($dueDate);
                    $daysOverdue = (int)$interval->format('%a');
                    $penalty = $daysOverdue * $penaltyRate;
                }
            } catch (Exception $e) { /* Ignore date parse errors */ }
        }
        
        // Finalize record data
        $record['calculated_penalty'] = $penalty;
        $record['is_returned'] = $isReturned;
        $record['status_label'] = $isReturned ? 'Returned' : 'Currently Borrowed';

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

$total_records = count($report_data);
$total_penalty_sum = array_sum(array_column($report_data, 'calculated_penalty'));

// --- NEW STYLED EXCEL (XLSX) EXPORT LOGIC ---
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Penalties Report');
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

    $sheet->setCellValue('A' . $currentRow, 'Total Records with Penalties:');
    $sheet->setCellValue('B' . $currentRow, $total_records);
    $sheet->getStyle('A' . $currentRow . ':B' . $currentRow)->applyFromArray($summaryStyle);
    $sheet->getStyle('B' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');

    $currentRow++;
    $sheet->setCellValue('A' . $currentRow, 'Sum of All Penalties:');
    $sheet->setCellValue('B' . $currentRow, $total_penalty_sum);
    $sheet->getStyle('A' . $currentRow . ':B' . $currentRow)->applyFromArray($summaryStyle);
    $sheet->getStyle('B' . $currentRow)->getNumberFormat()->setFormatCode('₱#,##0.00');

    $currentRow += 2; // Two blank rows after summary

    // --- 3. TABLE HEADERS (Green color) ---
    $headerRow = $currentRow;
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '107C41']], // Dark Green
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
    ];
    $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray($headerStyle);
    
    $headers = ['Book Title', 'Student Name', 'Student No', 'Borrow Date', 'Due Date', 'Return Date', 'Penalty (₱)'];
    $sheet->fromArray($headers, NULL, "A{$headerRow}");
    
    // --- SET COLUMN WIDTHS ---
    $sheet->getColumnDimension('A')->setWidth(40);
    $sheet->getColumnDimension('B')->setWidth(30);
    $sheet->getColumnDimension('C')->setWidth(18);
    $sheet->getColumnDimension('D')->setWidth(18);
    $sheet->getColumnDimension('E')->setWidth(18);
    $sheet->getColumnDimension('F')->setWidth(18);
    $sheet->getColumnDimension('G')->setWidth(15);

    $currentRow++; // Move to data start row

    // --- 4. DATA ROWS ---
    foreach ($report_data as $record) {
        $status = $record['is_returned'] ? 'Returned' : 'Not Returned';
        
        $sheet->setCellValue('A' . $currentRow, $record['book_details']['title'] ?? 'N/A');
        $sheet->setCellValue('B' . $currentRow, ($record['student_details']['first_name'] ?? 'Student') . ' ' . ($record['student_details']['last_name'] ?? 'Not Found'));
        $sheet->setCellValue('C' . $currentRow, $record['student_no'] ?? 'N/A');
        $sheet->setCellValue('D' . $currentRow, $record['borrow_date'] ?? '');
        $sheet->setCellValue('E' . $currentRow, $record['due_date'] ?? '');
        $sheet->setCellValue('F' . $currentRow, $record['return_date'] ?? 'Not Returned');
        $sheet->setCellValue('G' . $currentRow, $record['calculated_penalty']);
        
        // --- Cell Styling ---
        $sheet->getStyle('G' . $currentRow)->getNumberFormat()->setFormatCode('₱#,##0.00');
        $sheet->getStyle('G' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Color penalty if > 0
        if ($record['calculated_penalty'] > 0) {
             $sheet->getStyle('G' . $currentRow)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0000'))->setBold(true);
        }
        
        // Color status
        if ($status === 'Returned') {
             $sheet->getStyle('F' . $currentRow)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('008000'));
        }

        $currentRow++;
    }

    // --- 5. ADD TOTALS AT THE BOTTOM ---
    $currentRow++; // Add a blank row
    $sheet->setCellValue('F' . $currentRow, 'Total Records:');
    $sheet->setCellValue('G' . $currentRow, $total_records);
    $sheet->getStyle('F' . $currentRow . ':G' . $currentRow)->getFont()->setBold(true);

    $currentRow++;
    $sheet->setCellValue('F' . $currentRow, 'Total Penalties:');
    $sheet->setCellValue('G' . $currentRow, $total_penalty_sum);
    $sheet->getStyle('F' . $currentRow . ':G' . $currentRow)->getFont()->setBold(true);
    $sheet->getStyle('G' . $currentRow)->getNumberFormat()->setFormatCode('₱#,##0.00');

    // --- GENERATE AND SEND THE FILE ---
    $writer = new Xlsx($spreadsheet);
    $filename = str_replace(' ', '_', $reportTitle) . '_' . date('Y-m-d') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;
}

// --- HTML page starts here ---
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/sidebar.php';
?>

<main id="main-content" class="flex-1 p-6 md:p-10">
    <header class="flex flex-col md:flex-row justify-between md:items-center mb-8">
        <div>
            <h1 class="text-4xl font-bold tracking-tight"><?= htmlspecialchars($reportTitle) ?></h1>
            <p class="text-secondary mt-2">A complete list of all historical and current transactions with penalties.</p>
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
            <div class="bg-blue-100 p-3 rounded-full"><i data-lucide="list-checks" class="w-7 h-7 text-blue-600"></i></div>
            <div>
                <p class="text-secondary">Total Records with Penalties</p>
                <p class="text-3xl font-bold"><?php echo number_format($total_records); ?></p>
            </div>
        </div>
        <div class="bg-card p-6 rounded-xl border border-theme flex items-center gap-4 shadow-sm">
            <div class="bg-purple-100 p-3 rounded-full"><i data-lucide="piggy-bank" class="w-7 h-7 text-purple-600"></i></div>
            <div>
                <p class="text-secondary">Sum of All Penalties</p>
                <p class="text-3xl font-bold">₱<?php echo number_format($total_penalty_sum, 2); ?></p>
            </div>
        </div>
    </div>

    <div class="flex flex-col md:flex-row gap-4 mb-6">
    <div class="relative w-full md:w-1/2">
        <input type="text" id="searchInput" placeholder="Search by Book Title or Student Name..." class="w-full pl-10 pr-4 py-2 border border-theme rounded-lg focus:ring-2 focus:ring-primary focus:border-primary bg-card" onkeyup="filterTable()">
        <i data-lucide="search" class="w-5 h-5 absolute left-3 top-1/2 transform -translate-y-1/2 text-secondary"></i>
    </div>

        <select id="titleFilter" class="w-full md:w-1/4 py-2 px-4 border border-theme rounded-lg focus:ring-2 focus:ring-primary focus:border-primary bg-card" onchange="filterTable()">
            <option value="All Books">Filter by Book Title (All)</option>
            <?php foreach ($unique_titles as $title): ?>
                <option value="<?= htmlspecialchars($title) ?>"><?= htmlspecialchars($title) ?></option>
            <?php endforeach; ?>
        </select>

        <select id="statusFilter" class="w-full md:w-1/4 py-2 px-4 border border-theme rounded-lg focus:ring-2 focus:ring-primary focus:border-primary bg-card" onchange="filterTable()">
            <option value="All">Filter by Status (All)</option>
            <option value="Returned">Returned</option>
            <option value="Currently Borrowed">Currently Borrowed</option>
        </select>
        
        <select id="penaltyFilter" class="w-full md:w-1/4 py-2 px-4 border border-theme rounded-lg focus:ring-2 focus:ring-primary focus:border-primary bg-card" onchange="filterTable()">
            <option value="All">Filter by Penalty Range</option>
            <option value="1-50">₱1 - ₱50</option>
            <option value="51-100">₱51 - ₱100</option>
            <option value="101+">₱101+</option>
        </select>
    </div>
    <div class="bg-card rounded-lg border border-theme shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left" id="penaltyTable">
                <thead class="text-xs text-secondary uppercase bg-body sticky top-0">
                    <tr>
                        <th class="px-6 py-4">Book & Student</th>
                        <th class="px-6 py-4">Dates</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Penalty</th>
                    </tr>
                </thead>
                <tbody class="bg-card">
                    <?php if (empty($report_data) && $db_error === null): ?>
                        <tr id="initial-no-data-row"><td colspan="4" class="text-center py-12 text-secondary">No records with penalties found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($report_data as $record): ?>
                            <?php
                                $bookTitle = $record['book_details']['title'] ?? 'Book Not Found';
                                $studentName = ($record['student_details']['first_name'] ?? 'Student') . ' ' . ($record['student_details']['last_name'] ?? 'Not Found');
                            ?>
                            <tr class="border-b border-theme hover:bg-body"
                                data-book-title="<?= htmlspecialchars($bookTitle) ?>"
                                data-status="<?= htmlspecialchars($record['status_label']) ?>"
                                data-penalty="<?= $record['calculated_penalty'] ?>"
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
                                    <div><strong>Borrowed:</strong> <?= (new DateTime($record['borrow_date']))->format('M d, Y') ?></div>
                                    <div class="font-medium text-red-600 mt-1"><strong>Due:</strong> <?= (new DateTime($record['due_date']))->format('M d, Y') ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($record['is_returned']): ?>
                                        <div class="font-semibold text-green-700">Returned</div>
                                        <div class="text-xs"><?= (new DateTime($record['return_date']))->format('M d, Y') ?></div>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">Currently Borrowed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 font-bold text-lg <?= $record['calculated_penalty'] > 0 ? 'text-orange-600' : 'text-green-600' ?>">
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
        const statusFilter = document.getElementById('statusFilter').value;
        const penaltyFilter = document.getElementById('penaltyFilter').value;
        
        const tableBody = document.querySelector('#penaltyTable tbody');
        const rows = tableBody.querySelectorAll('tr');

        let visibleRowCount = 0;
        const noDataRowId = 'no-matching-data-row';
        const initialNoDataRowId = 'initial-no-data-row';

        // Helper function to check if a penalty falls within a range string
        function checkPenaltyRange(penalty, rangeString) {
            if (rangeString === 'All') return true;
            
            const numericPenalty = parseFloat(penalty);

            if (rangeString === '101+') return numericPenalty > 100;

            const [minStr, maxStr] = rangeString.split('-');
            const min = parseFloat(minStr);
            const max = parseFloat(maxStr);

            return numericPenalty >= min && numericPenalty <= max;
        }

        // Hide the initial no-data row if it exists
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
            const rowStatus = row.getAttribute('data-status') || '';
            const rowPenalty = row.getAttribute('data-penalty') || 0;

            // 1. Search Filter (Book Title or Student Name)
            const passesSearch = rowSearchTerm.includes(searchTerm);

            // 2. Title Filter
            const passesTitle = titleFilter === 'All Books' || rowTitle === titleFilter;
            
            // 3. Status Filter
            const passesStatus = statusFilter === 'All' || rowStatus === statusFilter;
            
            // 4. Penalty Filter
            const passesPenalty = checkPenaltyRange(rowPenalty, penaltyFilter);


            // Show or hide the row based on all filters
            if (passesSearch && passesTitle && passesStatus && passesPenalty) {
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
                noDataRow.innerHTML = `<td colspan="4" class="text-center py-12 text-secondary">No matching penalty records found.</td>`;
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