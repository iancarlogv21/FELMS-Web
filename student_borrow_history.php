<?php
// Make sure to include the Composer autoloader at the top
require_once __DIR__ . '/vendor/autoload.php'; 
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

// Use statements for the PhpSpreadsheet library
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// --- 1. INITIAL SETUP & INPUT HANDLING ---
$pageTitle = 'Student Borrow History';
$currentPage = 'students';
// ... (rest of the input handling code is the same)
$studentId = $_GET['id'] ?? null;
$isExportRequest = isset($_GET['export']);

if (!$studentId || !preg_match('/^[a-f\d]{24}$/i', $studentId)) {
    $_SESSION['error_message'] = "Invalid Student ID format."; header("Location: student.php"); exit();
}

$student = null; $borrow_history = []; $db_error = null;

try {
    $studentObjectId = new MongoDB\BSON\ObjectID($studentId);
    $dbInstance = Database::getInstance();
    $studentsCollection = $dbInstance->students();
    $borrowCollection = $dbInstance->borrows();
    $booksCollection = $dbInstance->books();

    $student = $studentsCollection->findOne(['_id' => $studentObjectId]);

    if (!$student) {
        $_SESSION['error_message'] = "Student not found."; header("Location: student.php"); exit();
    }

    // --- 2. HANDLE PROFESSIONAL EXCEL EXPORT REQUEST ---
    if ($isExportRequest) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // --- STYLING ---
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A3:A5')->getFont()->setBold(true);
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4285F4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ];
        $sheet->getStyle('A7:E7')->applyFromArray($headerStyle);
        
        // --- PAGE SETUP & HEADERS ---
        $sheet->setTitle('Borrow History');
        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', 'Borrow History Report');

        $sheet->setCellValue('A3', 'Student Name:');
        $sheet->setCellValue('B3', formatName($student));
        $sheet->setCellValue('A4', 'Program & Year:');
        $sheet->setCellValue('B4', ($student['program'] ?? 'N/A') . ' - Year ' . ($student['year'] ?? 'N/A'));
        $sheet->setCellValue('A5', 'Date Exported:');
        $sheet->setCellValue('B5', date('Y-m-d H:i:s'));

        // --- TABLE HEADERS ---
        $sheet->setCellValue('A7', 'ISBN');
        $sheet->setCellValue('B7', 'Book Title');
        $sheet->setCellValue('C7', 'Borrow Date');
        $sheet->setCellValue('D7', 'Due Date');
        $sheet->setCellValue('E7', 'Return Date');
        
        // --- SET COLUMN WIDTHS ---
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(50);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(20);

        // --- FETCH & POPULATE DATA ---
        $pipeline = [
            ['$match' => ['student_no' => $student['student_no']]],
            ['$sort' => ['borrow_date' => -1]],
            ['$lookup' => ['from' => 'books', 'localField' => 'isbn', 'foreignField' => 'isbn', 'as' => 'book_details']],
            ['$unwind' => ['path' => '$book_details', 'preserveNullAndEmptyArrays' => true]]
        ];
        $historyCursor = $borrowCollection->aggregate($pipeline);

        $rowIndex = 8;
        foreach ($historyCursor as $record) {
            $sheet->setCellValue('A' . $rowIndex, $record['isbn'] ?? '');
            $sheet->setCellValue('B' . $rowIndex, $record['book_details']['title'] ?? ($record['title'] ?? 'N/A'));
            $sheet->setCellValue('C' . $rowIndex, formatMongoDate($record['borrow_date']));
            $sheet->setCellValue('D' . $rowIndex, formatMongoDate($record['due_date']));
            $sheet->setCellValue('E' . $rowIndex, !empty($record['return_date']) ? formatMongoDate($record['return_date']) : 'Not Returned');
            $rowIndex++;
        }

        // --- GENERATE AND SEND THE FILE ---
        $writer = new Xlsx($spreadsheet);
        $filename = "Borrow_History_" . str_replace(' ', '_', formatName($student)) . "_" . date('Y-m-d') . ".xlsx";

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $writer->save('php://output');
        exit();
    }

    // --- 3. FETCH DATA FOR NORMAL PAGE VIEW ---
    // (This part remains the same as your original optimized code)
    $borrow_history = [];
    $pipeline = [
        ['$match' => ['student_no' => $student['student_no']]],
        ['$sort' => ['borrow_date' => -1]],
        ['$lookup' => ['from' => 'books', 'localField' => 'isbn', 'foreignField' => 'isbn', 'as' => 'book_details']],
        ['$unwind' => ['path' => '$book_details', 'preserveNullAndEmptyArrays' => true]]
    ];
    $historyCursor = $borrowCollection->aggregate($pipeline);
    foreach ($historyCursor as $record) {
        $record['book_title'] = $record['book_details']['title'] ?? ($record['title'] ?? 'Title Not Recorded');
        $record['book_thumbnail'] = getOrFetchBookThumbnail(
            $record['isbn'] ?? null,
            $record['book_details']['thumbnail_url'] ?? null,
            $booksCollection
        );
        $borrow_history[] = $record;
    }

} catch (Exception $e) {
    $db_error = "Database Error: " . $e->getMessage();
}

require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/sidebar.php';
?>

<main id="main-content" class="flex-1 p-6 md:p-10">
    <div class="max-w-none mx-auto px-4 sm:px-6 lg:px-8">
        <?php if ($student): ?>
            <div class="bg-card p-6 rounded-2xl border border-theme shadow-sm mb-8 flex flex-col md:flex-row items-center gap-6">
                <img src="<?php echo getStudentPhotoUrl($student); ?>" alt="Student Photo" class="w-28 h-28 rounded-full object-cover ring-4 ring-white dark:ring-slate-800 shadow-lg flex-shrink-0">
                <div class="flex-grow text-center md:text-left">
                    <h1 class="text-4xl font-bold text-primary"><?php echo htmlspecialchars(formatName($student)); ?></h1>
                    <p class="text-secondary mt-1 text-lg">
                        <span><?php echo htmlspecialchars($student['program'] ?? 'N/A'); ?></span> &bull;
                        <span>Year <?php echo htmlspecialchars($student['year'] ?? 'N/A'); ?></span>
                    </p>
                </div>
                <div class="flex flex-col md:flex-row gap-3 mt-4 md:mt-0 w-full md:w-auto">
                    <a href="?id=<?php echo htmlspecialchars($studentId); ?>&export=true" class="inline-flex items-center justify-center gap-2 rounded-lg px-5 py-3 font-semibold text-white bg-green-600 hover:bg-green-700 transition-colors shadow-md hover:shadow-lg">
                        <i data-lucide="file-spreadsheet" class="w-5 h-5"></i>
                        <span>Export to Excel</span>
                    </a>
                    <a href="student.php" class="inline-flex items-center justify-center gap-2 rounded-lg px-5 py-3 font-semibold text-white bg-sky-500 hover:bg-sky-600 active:bg-sky-700 transition-all duration-200 shadow-md hover:shadow-lg">
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                        <span>Back to Students</span>
                    </a>
                </div>
            </div>

            <div class="bg-card rounded-2xl border border-theme shadow-sm overflow-hidden">
                <?php if ($db_error): ?>
                    <div class="p-6 text-red-700 bg-red-100 rounded-lg"><?php echo $db_error; ?></div>
                <?php elseif (empty($borrow_history)): ?>
                    <div class="text-center p-16 text-secondary">
                        <p>No borrowing history found for this student.</p>
                    </div>
                <?php else: ?>
                    
                    <div class="p-4 **bg-card** border-b **border-theme** flex flex-col md:flex-row gap-4 justify-between items-center">
                        
                        <div class="relative flex-1 w-full"> 
                            <input 
                                type="text" 
                                id="borrow-search" 
                                placeholder="Search by ISBN or Title..." 
                                class="w-full pl-10 pr-4 py-2 border border-theme rounded-lg focus:ring-2 focus:ring-primary focus:border-primary bg-card text-primary transition-all duration-200" 
                                onkeyup="filterTable()">
                            <i data-lucide="search" class="w-5 h-5 absolute left-3 top-1/2 transform -translate-y-1/2 text-secondary"></i>
                        </div>

                        <div class="relative w-full md:w-56 flex-shrink-0">
                            <select 
                                id="return-filter" 
                                class="w-full py-2 px-3 border border-theme rounded-lg focus:ring-2 focus:ring-primary focus:border-primary bg-card text-primary appearance-none pr-8 transition-all duration-200" 
                                onchange="filterTable()">
                                <option value="all">Filter by Status (All)</option>
                                <option value="returned">Returned</option>
                                <option value="not_returned">Not Returned</option>
                            </select>
                            <i data-lucide="chevron-down" class="w-4 h-4 text-secondary absolute right-3 top-1/2 transform -translate-y-1/2 pointer-events-none"></i>
                        </div>
                        
                    </div>
                    <div class="bg-card rounded-lg shadow-sm overflow-hidden">
                        <div class="block w-full overflow-x-auto">
                            <div class="max-h-[60vh] overflow-y-auto">
                                <table class="w-full text-sm text-left text-[var(--text-secondary)]">
                                    <thead class="text-xs uppercase bg-[var(--border-color)] text-[var(--text-primary)] sticky top-0">
                                        <tr>
                                            <th class="px-6 py-4 text-left text-sm font-semibold text-secondary uppercase tracking-wider">Book</th>
                                            <th class="px-6 py-4 text-left text-sm font-semibold text-secondary uppercase tracking-wider">Borrow Date</th>
                                            <th class="px-6 py-4 text-left text-sm font-semibold text-secondary uppercase tracking-wider">Due Date</th>
                                            <th class="px-6 py-4 text-left text-sm font-semibold text-secondary uppercase tracking-wider">Return Date</th>
                                        </tr>
                                    </thead>
                                    <tbody id="borrow-history-body" class="divide-y divide-theme">
                                        <?php foreach ($borrow_history as $record): ?>
                                            <?php
                                                $is_returned = !empty($record['return_date']);
                                                $return_status_class = $is_returned ? 'returned' : 'not_returned';
                                                $return_date_display = $is_returned 
                                                    ? formatMongoDate($record['return_date']) 
                                                    : '<span class="text-red-500 dark:text-red-400 font-medium">Not Returned</span>';
                                            ?>
                                            <tr class="table-row-item <?php echo $return_status_class; ?>" data-search-terms="<?php echo htmlspecialchars(strtolower($record['book_title'] . ' ' . ($record['isbn'] ?? ''))); ?>">
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center gap-4">
                                                        <?php if ($record['book_thumbnail']): ?>
                                                            <img src="<?php echo htmlspecialchars($record['book_thumbnail']); ?>" alt="Book Cover" class="w-12 h-16 object-cover rounded-md flex-shrink-0 bg-slate-200 shadow-sm">
                                                        <?php else: ?>
                                                            <div class="w-12 h-16 rounded-md flex-shrink-0 bg-slate-200 dark:bg-slate-700 flex items-center justify-center shadow-sm">
                                                                <i data-lucide="book-open" class="w-6 h-6 text-slate-400 dark:text-slate-500"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div>
                                                            <p class="font-bold text-primary"><?php echo htmlspecialchars($record['book_title']); ?></p>
                                                            <p class="text-sm text-secondary font-mono mt-1"><?php echo htmlspecialchars($record['isbn'] ?? ''); ?></p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-secondary"><?php echo formatMongoDate($record['borrow_date']); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-secondary"><?php echo formatMongoDate($record['due_date']); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-secondary">
                                                    <?php echo $return_date_display; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif ($db_error): ?>
            <div class="p-6 text-red-700 bg-red-100 rounded-lg"><?php echo $db_error; ?></div>
        <?php endif; ?>
    </div>
</main>

<script>
    // JavaScript function remains the same, as the logic handles both the search and filter.
    function filterTable() {
        const searchText = document.getElementById('borrow-search').value.toLowerCase().trim();
        const filterStatus = document.getElementById('return-filter').value;
        const tableRows = document.querySelectorAll('#borrow-history-body .table-row-item');
        const noDataRowId = 'no-matching-data-row';
        let visibleRowCount = 0;

        // Clean up the dynamic "No matching data" row before filtering
        let existingNoDataRow = document.getElementById(noDataRowId);
        if (existingNoDataRow) {
            existingNoDataRow.remove();
        }

        tableRows.forEach(row => {
            const searchTerms = row.getAttribute('data-search-terms');
            const rowStatus = row.classList.contains('returned') ? 'returned' : 'not_returned';
            
            // 1. Search Filtering
            const matchesSearch = searchTerms.includes(searchText);

            // 2. Status Filtering
            let matchesStatus = true;
            if (filterStatus !== 'all') {
                matchesStatus = rowStatus === filterStatus;
            }

            // Show or hide the row
            if (matchesSearch && matchesStatus) {
                row.style.display = ''; // Show the row
                visibleRowCount++;
            } else {
                row.style.display = 'none'; // Hide the row
            }
        });

        // Add "No matching data" row if no rows are visible
        if (visibleRowCount === 0) {
            const tableBody = document.getElementById('borrow-history-body');
            const noDataRow = document.createElement('tr');
            noDataRow.id = noDataRowId;
            noDataRow.innerHTML = `<td colspan="4" class="text-center py-12 text-secondary">No borrowing records match your criteria.</td>`;
            tableBody.appendChild(noDataRow);
        }
    }
    
    // Ensure filtering runs on page load if data is present
    document.addEventListener('DOMContentLoaded', filterTable);
</script>

<?php
require_once __DIR__ . '/templates/footer.php';
?>