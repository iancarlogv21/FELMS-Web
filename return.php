<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';
session_start();

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
// == UNIFIED FILTERING & EXPORT LOGIC
// =========================================================================

$filter_search = trim($_GET['search'] ?? '');
$filter_year = trim($_GET['year'] ?? '');
$filter_month = trim($_GET['month'] ?? '');
$filter_day = trim($_GET['day'] ?? '');

// --- ★★★ FIX 1: THE MONGODB PIPELINE ★★★ ---
// This pipeline now correctly looks up books by EITHER 
// the 'isbn' OR the 'accession_number' from the returns collection.
$pipeline = [
    ['$sort' => ['return_date' => -1]],
    ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
    
    // --- THIS $lookup IS NOW CORRECT ---
    ['$lookup' => [
        'from' => 'AddBook',
        'let' => [
            'book_isbn' => '$isbn',
            'book_acc' => '$accession_number'
        ],
        'pipeline' => [
            ['$match' => [
                '$expr' => ['$or' => [
                    // Match by ISBN (if it exists)
                    ['$and' => [
                        ['$ne' => ['$$book_isbn', null]],
                        ['$eq' => ['$isbn', '$$book_isbn']]
                    ]],
                    // Match by Accession Number (if it exists)
                    ['$and' => [
                        ['$ne' => ['$$book_acc', null]],
                        ['$eq' => ['$accession_number', '$$book_acc']]
                    ]]
                ]]
            ]],
            ['$limit' => 1]
        ],
        'as' => 'book_details'
    ]],
    // --- END OF FIX ---

    ['$unwind' => ['path' => '$student_details', 'preserveNullAndEmptyArrays' => true]],
    ['$unwind' => ['path' => '$book_details', 'preserveNullAndEmptyArrays' => true]],
];

// --- Build the $match stage based on filters ---
$match = [];

if (!empty($filter_search)) {
    // This now correctly searches all relevant fields
    $match['$or'] = [
        ['student_details.first_name' => ['$regex' => $filter_search, '$options' => 'i']],
        ['student_details.last_name' => ['$regex' => $filter_search, '$options' => 'i']],
        ['book_details.title' => ['$regex' => $filter_search, '$options' => 'i']],
        ['title' => ['$regex' => $filter_search, '$options' => 'i']], // Search title on return doc
        ['return_id' => ['$regex' => $filter_search, '$options' => 'i']],
        ['student_no' => ['$regex' => $filter_search, '$options' => 'i']],
        ['isbn' => ['$regex' => $filter_search, '$options' => 'i']],
        ['accession_number' => ['$regex' => $filter_search, '$options' => 'i']],
    ];
}

$date_conditions = [];
if (!empty($filter_year)) {
    $date_conditions[] = ['$eq' => [['$year' => ['$toDate' => '$return_date']], (int)$filter_year]];
}
if (!empty($filter_month)) {
    $date_conditions[] = ['$eq' => [['$month' => ['$toDate' => '$return_date']], (int)$filter_month]];
}
if (!empty($filter_day)) {
    $date_conditions[] = ['$eq' => [['$dayOfMonth' => ['$toDate' => '$return_date']], (int)$filter_day]];
}

if (!empty($date_conditions)) {
    $match['$expr'] = ['$and' => $date_conditions];
}

if (!empty($match)) {
    $pipeline[] = ['$match' => $match];
}

// --- EXCEL EXPORT HANDLER ---
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $db = Database::getInstance();
    $returnsCollection = $db->returns();
    $cursor = $returnsCollection->aggregate($pipeline);
    $dataToExport = iterator_to_array($cursor);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    $titleStyle = [ 'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1E40AF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER] ];
    $headerStyle = [ 'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER] ];
    $dateStyle = [ 'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '4B5563']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER] ];

    $sheet->mergeCells('A1:F1');
    $sheet->setCellValue('A1', 'FELMS - Return History Report');
    $sheet->getStyle('A1')->applyFromArray($titleStyle);
    $sheet->mergeCells('A2:F2');
    $sheet->setCellValue('A2', 'Generated on: ' . date('F j, Y, g:i a'));
    $sheet->getStyle('A2')->applyFromArray($dateStyle);
    $headers = ['Book Title', 'Student Name', 'Student No.', 'Return ID', 'Return Date', 'Penalty Paid'];
    $sheet->fromArray($headers, NULL, 'A4');
    $sheet->getStyle('A4:F4')->applyFromArray($headerStyle);

    $row = 5;
    foreach ($dataToExport as $doc) {
        $returnDate = 'N/A';
        try {
            $rd = $doc['return_date'];
            if ($rd instanceof MongoDB\BSON\UTCDateTime) {
                $returnDate = $rd->toDateTime()->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i');
            } elseif (is_string($rd)) {
                $returnDate = (new DateTime($rd))->format('Y-m-d H:i');
            }
        } catch (Exception $e) {}
        
        $bookTitle = $doc['title'] ?? $doc['book_details']['title'] ?? 'N/A';
        
        $sheet->setCellValue('A' . $row, $bookTitle);
        $sheet->setCellValue('B' . $row, ($doc['student_details']['first_name'] ?? '') . ' ' . ($doc['student_details']['last_name'] ?? 'N/A'));
        $sheet->setCellValue('C' . $row, $doc['student_no'] ?? 'N/A');
        $sheet->setCellValue('D' . $row, $doc['return_id'] ?? 'N/A');
        $sheet->setCellValue('E' . $row, $returnDate);
        $sheet->setCellValue('F' . $row, $doc['penalty'] ?? 0);
        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');
        $row++;
    }

    foreach (range('A', 'F') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $filename = 'FELMS_Return_History_' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// --- AJAX REQUEST HANDLER ---
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $db = Database::getInstance();
    $returnsCollection = $db->returns();
    $returnedBooksCursor = $returnsCollection->aggregate($pipeline);
    renderReturnHistoryRows($returnedBooksCursor);
    exit;
}

// --- NORMAL PAGE LOAD ---
$currentPage = 'return';
$pageTitle = 'Return Books - FELMS';
$db = Database::getInstance();
$returnsCollection = $db->returns();
$returnedBooksCursor = $returnsCollection->aggregate($pipeline);


// --- ★★★ FIX 2: THE PHP RENDER FUNCTION ★★★ ---
// This function now correctly finds the thumbnail and identifier
// for ALL books, using the logic from your borrow.php file.
function renderReturnHistoryRows($cursor) {
    $has_results = false;
    foreach ($cursor as $doc) {
        $has_results = true;
        // This line is CRITICAL. It ensures $book is reset for every loop.
        $book = (array) ($doc['book_details'] ?? []); 
        $student = (array) ($doc['student_details'] ?? []);
        
        // 1. Get Title (Fallback)
        $bookTitle = $doc['title'] ?? $book['title'] ?? 'N/A';
        
        // 2. Get the correct Identifier (ISBN or Accession Number)
        $bookIdentifier = $doc['isbn'] ?? $doc['accession_number'] ?? 'N/A';
        
        // 3. Determine the correct label ("ISBN" or "ACC")
        $identifierLabel = ($doc['isbn'] ?? null) ? 'ISBN' : 'Accession Number';
        
        // 4. Get the Book Cover from the *joined* book details ('$book')
        // This fixes the "Wuthering Heights" thumbnail bug
        $bookCover = $book['thumbnail'] ?? 'https://placehold.co/80x120/E2E8F0/4A5568?text=N/A';
        
        
        $studentPhoto = getStudentPhotoUrl($student);
        $penalty = $doc['penalty'] ?? 0;
        $returnDateFormatted = 'N/A';
        try {
            $rd = $doc['return_date'];
            if ($rd instanceof MongoDB\BSON\UTCDateTime) {
                $returnDateFormatted = $rd->toDateTime()->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y, h:i A');
            } elseif (is_string($rd)) {
                $returnDateFormatted = (new DateTime($rd, new DateTimeZone('Asia/Manila')))->format('M d, Y, h:i A');
            }
        } catch (Exception $e) {}

        $returnMongoId = (string)$doc['_id'];
       
        // We add the data-row-id for the delete JavaScript
        echo "<tr data-row-id='" . htmlspecialchars($returnMongoId) . "'>";
        
        // Use the new variables to display the correct info
        echo "<td class='px-6 py-4'><div class='flex items-center gap-3'><img src='" . htmlspecialchars($bookCover) . "' alt='Book Cover' class='w-10 h-14 object-cover rounded-sm'><div><div class='font-medium text-primary'>" . htmlspecialchars($bookTitle) . "</div><div class='text-xs text-secondary font-mono mt-1'>{$identifierLabel}: " . htmlspecialchars($bookIdentifier) . "</div></div></div></td>";
        
        echo "<td class='px-6 py-4'><div class='flex items-center gap-3'><img src='" . htmlspecialchars($studentPhoto) . "' alt='Student' class='w-10 h-10 rounded-full object-cover'><div><div class='font-medium text-primary'>" . htmlspecialchars($student['first_name'] ?? '') . ' ' . htmlspecialchars($student['last_name'] ?? 'N/A') . "</div><div class='text-secondary'>" . htmlspecialchars($doc['student_no']) . "</div></div></div></td>";
        echo "<td class='px-6 py-4 font-mono text-secondary'>" . htmlspecialchars($doc['return_id']) . "</td>";
        echo "<td class='px-6 py-4 text-secondary'>" . $returnDateFormatted . "</td>";
        echo "<td class='px-6 py-4'><span class='px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full " . ($penalty > 0 ? 'bg-red-500 text-white' : 'bg-green-500 text-white') . "'>₱" . number_format($penalty, 2) . "</span></td>";
        echo "<td class='px-6 py-4 text-right'><button data-return-id='" . htmlspecialchars($returnMongoId) . "' class='delete-return-btn p-2 text-secondary/60 hover:bg-red-100 hover:text-red-600 rounded-md transition-colors' title='Delete Record'><i data-lucide='trash-2' class='w-4 h-4'></i></button></td>";
        echo "</tr>";
    }
    if (!$has_results) {
        echo '<tr><td colspan="6" class="text-center py-10 text-secondary">No return history found for the selected filters.</td></tr>';
    }
}
// --- END OF FIX ---

require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/sidebar.php';
?>


<main id="main-content" class="flex-1 p-6 lg:p-8">
    <header class="mb-8">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-primary">Return Book</h1>
                <p class="text-secondary mt-1">Process returns in real-time and manage your library's circulation history.</p>
            </div>
            <div class="text-sm text-secondary">
                <span>FELMS</span> /
                <span class="font-semibold text-primary">Return Book</span>
            </div>
        </div>
    </header>

    <div id="status-message" class="hidden mb-6 p-4 rounded-lg items-center gap-3"></div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-8 mb-8">
        <div class="lg:col-span-3 bg-card p-6 rounded-xl shadow-md border border-theme">
            <div class="flex items-start gap-4">
                <div class="bg-red-100 text-red-600 p-3 rounded-lg"><i data-lucide="barcode" class="w-6 h-6"></i></div>
                <div>
                    <h2 class="text-xl font-bold text-primary">Scan Barcode</h2>
                    <p class="text-secondary mt-1 mb-6">Use a scanner or manually type the Borrow ID to instantly see return details.</p>
                </div>
            </div>
            <div>
                <label for="borrow_id_input" class="block text-sm font-medium text-secondary mb-1">Borrow Transaction ID</label>
                <div class="relative">
                    <i data-lucide="scan-line" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-secondary"></i>
                    <input type="text" name="borrow_id" id="borrow_id_input" 
    class="w-full pl-12 pr-4 py-3 
           border border-theme 
           bg-white dark:bg-white         /* STATIC LIGHT BACKGROUND */
           rounded-lg shadow-sm 
           focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 
           text-lg text-gray-900 dark:text-gray-900" /* STATIC DARK TEXT */
    placeholder="Scan or type ID..." 
    autofocus>
                </div>
            </div>
        </div>
        <div class="lg:col-span-2 bg-card p-6 rounded-xl shadow-md border border-theme">
            <h2 class="text-xl font-bold text-primary mb-4">Return Details</h2>
            <div id="details-container">
                <div class="text-center py-10" id="details-placeholder"><i data-lucide="scan-line" class="mx-auto w-12 h-12 text-secondary/40"></i><p class="mt-4 text-secondary">Scan a barcode to see details here.</p></div>
                <div class="text-center py-10 hidden" id="details-loader"><i data-lucide="loader-2" class="mx-auto w-12 h-12 text-secondary/40 animate-spin"></i><p class="mt-4 text-secondary">Fetching details...</p></div>
                <div class="hidden" id="details-content">
                    <div class="flex items-center gap-4 mb-4">
                        <img id="details-book-cover" src="" alt="Book Cover" class="w-20 h-28 object-cover rounded-md shadow-lg">
                        <div>
                            <p id="details-book-title" class="font-bold text-lg text-primary"></p>
                            <div class="mt-2 flex items-center gap-3">
                                <img id="details-student-photo" src="" alt="Student" class="w-10 h-10 rounded-full object-cover">
                                <p id="details-student-name" class="font-semibold text-secondary"></p>
                            </div>
                        </div>
                    </div>
                    <div class="text-sm space-y-2 pt-4 border-t border-theme">
                        <div class="flex justify-between"><span class="text-secondary">Receipt ID:</span><strong id="details-borrow-id" class="text-primary font-mono"></strong></div>
                        <div class="flex justify-between"><span class="text-secondary">Borrow Date:</span><strong id="details-borrow-date" class="text-primary"></strong></div>
                        <div class="flex justify-between"><span class="text-secondary">Due Date:</span><strong id="details-due-date" class="text-primary"></strong></div>
                    </div>
                    <button id="confirm-return-btn" class="mt-4 w-full flex justify-center items-center gap-2 py-3 px-4 border border-transparent rounded-lg shadow-sm text-base font-semibold text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                        <i data-lucide="check-circle"></i> Confirm Return
                    </button>
                </div>
                <div class="text-center py-10 hidden" id="details-error"><i data-lucide="alert-triangle" class="mx-auto w-12 h-12 text-amber-400"></i><p class="mt-4 text-secondary font-semibold" id="details-error-message"></p></div>
            </div>
        </div>
    </div>
    <div class="bg-card p-6 rounded-xl shadow-md border border-theme">
    <h2 class="text-2xl font-bold text-primary">Return History</h2>
    <p class="text-secondary mb-6 mt-1">A log of the most recently processed returns.</p>

    <div class="bg-body p-6 rounded-xl border border-theme mb-8">
    <form id="history-filter-form" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 items-end">
        
       <div class="lg:col-span-2">
            <label for="search-input" class="block text-sm font-medium text-secondary mb-1">Search by Name/ID/Title</label>
            <div class="relative">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                <input type="text" id="search-input" name="search"
            class="w-full pl-10 pr-4 py-2.5 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-red-500 text-gray-800" 
            placeholder="Student, Book, Return ID..."
            value="<?= htmlspecialchars($filter_search) ?>">
    </div>
</div>
        <div>
            <label for="year-filter" class="block text-sm font-medium text-secondary mb-1">Year</label>
            <select id="year-filter" name="year" class="form-input w-full py-2.5 border-theme bg-card rounded-md shadow-sm">
                <option value="">All Year</option>
                <?php foreach (range(date('Y'), date('Y') - 5) as $year): ?>
                    <option value="<?= $year ?>" <?= ($filter_year == $year) ? 'selected' : '' ?>><?= $year ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="month-filter" class="block text-sm font-medium text-secondary mb-1">Month</label>
            <select id="month-filter" name="month" class="form-input w-full py-2.5 border-theme bg-card rounded-md shadow-sm">
                <option value="">All Month</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= ($filter_month == $m) ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        
        <div class="lg:col-span-2 flex items-center justify-end gap-3 w-full">
            <button type="submit" class="flex justify-center items-center gap-2 py-2 px-5 bg-red-600 text-white font-semibold rounded-md shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors duration-200">
                <i data-lucide="filter" class="w-5 h-5"></i>
                <span>Filter</span>
            </button>
            <a href="return.php" id="reset-btn" class="flex justify-center items-center gap-2 py-2 px-5 bg-gray-600 text-white font-semibold rounded-md shadow-sm hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors duration-200">
                <i data-lucide="rotate-cw" class="w-5 h-5"></i>
                <span>Reset</span>
            </a>
            <a href="#" id="export-excel-btn" class="flex justify-center items-center gap-2 py-2 px-5 bg-green-600 text-white font-semibold rounded-md shadow-sm hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors duration-200">
                <i data-lucide="file-spreadsheet" class="w-5 h-5"></i>
                <span>Export</span>
            </a>
        </div>
    </form>
</div>

    <div class="bg-card rounded-lg border border-theme shadow-sm overflow-hidden">
        <div class="block w-full overflow-x-auto">
            <div class="max-h-[60vh] overflow-y-auto">
                <table id="return-history-table" class="w-full text-sm text-left text-[var(--text-secondary)]">
                    <thead class="text-xs uppercase bg-[var(--border-color)] text-[var(--text-primary)] sticky top-0">
                    <tr class="border-b border-theme">
                        <th scope="col" class="px-6 py-4">Book</th>
                        <th scope="col" class="px-6 py-4">Student</th>
                        <th scope="col" class="px-6 py-4">Return ID</th>
                        <th scope="col" class="px-6 py-4">Return Date</th>
                        <th scope="col" class="px-6 py-4">Penalty</th>
                        <th scope="col" class="px-6 py-4 text-center">Actions</th>
                    </tr>
                </thead>
                
                <tbody class="divide-y divide-theme" id="return-history-tbody">
                    <?php renderReturnHistoryRows($returnedBooksCursor); ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // --- (All your const definitions remain the same) ---
    const borrowInput = document.getElementById('borrow_id_input');
    const placeholder = document.getElementById('details-placeholder');
    const loader = document.getElementById('details-loader');
    const content = document.getElementById('details-content');
    const errorEl = document.getElementById('details-error');
    const confirmBtn = document.getElementById('confirm-return-btn');
    const statusMessageEl = document.getElementById('status-message');
    let fetchTimeout;

    // --- (showStatusMessage function remains the same) ---
    const showStatusMessage = (type, message) => {
        statusMessageEl.className = `p-4 rounded-lg flex items-center gap-3 mb-6`;
        if (type === 'success') {
            statusMessageEl.classList.add('bg-green-100', 'text-green-800', 'dark:bg-green-900', 'dark:text-green-200');
            statusMessageEl.innerHTML = `<i data-lucide="check-circle"></i> <p>${message}</p>`;
        } else {
            statusMessageEl.classList.add('bg-red-100', 'text-red-800', 'dark:bg-red-900', 'dark:text-red-200');
            statusMessageEl.innerHTML = `<i data-lucide="alert-triangle"></i> <p>${message}</p>`;
        }
        lucide.createIcons();
        window.scrollTo({ top: 0, behavior: 'smooth' });
        setTimeout(() => {
            statusMessageEl.className = 'mb-6';
            statusMessageEl.innerHTML = '';
        }, 5000);
    };

    // --- (updateDetailsUI function remains the same) ---
    const updateDetailsUI = (state, data = null) => {
        [placeholder, loader, content, errorEl].forEach(el => el.classList.add('hidden'));
        confirmBtn.disabled = true;
        if (state === 'loading') {
            loader.classList.remove('hidden');
        } else if (state === 'error' || state === 'warning') {
            document.getElementById('details-error-message').textContent = data.message;
            errorEl.classList.remove('hidden');
        } else if (state === 'success') {
            confirmBtn.dataset.borrowId = data.borrow_id;
            document.getElementById('details-book-cover').src = data.book_cover;
            document.getElementById('details-book-title').textContent = data.title;
            document.getElementById('details-student-photo').src = data.student_photo;
            document.getElementById('details-student-name').textContent = data.student_name;
            document.getElementById('details-borrow-id').textContent = data.borrow_id;
            document.getElementById('details-borrow-date').textContent = data.borrow_date;
            document.getElementById('details-due-date').textContent = data.due_date;
            content.classList.remove('hidden');
            confirmBtn.disabled = false;
        } else {
            placeholder.classList.remove('hidden');
        }
        lucide.createIcons();
    };

    // --- (borrowInput event listener remains the same) ---
    borrowInput.addEventListener('input', () => {
        clearTimeout(fetchTimeout);
        const borrowId = borrowInput.value.trim();
        if (borrowId.length < 3) { 
            updateDetailsUI('idle'); 
            return; 
        }
        updateDetailsUI('loading');
        fetchTimeout = setTimeout(() => {
            // This now calls the FIXED api_get_borrow_details.php
            fetch(`api_get_borrow_details.php?borrow_id=${borrowId}`) 
                .then(res => res.json())
                .then(result => {
                    if (result.status === 'success') {
                        updateDetailsUI('success', { ...result.data, borrow_id: borrowId });
                    } else {
                        updateDetailsUI(result.status, { message: result.message });
                    }
                })
                .catch(() => updateDetailsUI('error', { message: 'Network error fetching details.' }));
        }, 500);
    });

    // --- ★★★ FIX 4: THE JAVASCRIPT RENDER ★★★ ---
    // This function also needs to be updated to show the correct identifier
    confirmBtn.addEventListener('click', async () => {
        const borrowIdToReturn = confirmBtn.dataset.borrowId;
        if (!borrowIdToReturn) return;

        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i data-lucide="loader-2" class="animate-spin"></i> Processing...';
        lucide.createIcons();

        try {
            const response = await fetch('api_process_return.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `borrow_id=${encodeURIComponent(borrowIdToReturn)}`
            });
            
            if (!response.ok) {
                throw new Error(`Server Error: HTTP status ${response.status}`);
            }
            
            const result = await response.json();

            if (result.status === 'success' && result.new_return) {
                showStatusMessage('success', result.message);
                updateDetailsUI('idle');
                borrowInput.value = '';

                const tableBody = document.getElementById("return-history-tbody");
                const newRow = document.createElement('tr');
                
                // --- This line is crucial for the delete button to work ---
                newRow.dataset.rowId = result.new_return._id; 
                
                const returnDate = new Date(result.new_return.return_date).toLocaleString('en-US', {
                    month: 'short', day: 'numeric', year: 'numeric', 
                    hour: 'numeric', minute: '2-digit', hour12: true,
                    timeZone: 'Asia/Manila'
                });
                
                const penalty = parseFloat(result.new_return.penalty);
                const penaltyClass = penalty > 0 ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800';
                const penaltyFormatted = '₱' + penalty.toFixed(2);
                
                // This JavaScript now matches the PHP function logic
                const identifier = result.new_return.isbn || result.new_return.accession_number;
                const identifierLabel = result.new_return.isbn ? 'ISBN' : 'ACC';
                // --- END OF FIX ---

                newRow.innerHTML = `
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <img src="${document.getElementById('details-book-cover').src}" alt="Book Cover" class="w-10 h-14 object-cover rounded-sm">
                            <div>
                                <div class="font-medium text-primary">${result.new_return.title}</div>
                                <div class="text-xs text-secondary font-mono mt-1">${identifierLabel}: ${identifier}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                         <div class="flex items-center gap-3">
                            <img src="${document.getElementById('details-student-photo').src}" alt="Student" class="w-10 h-10 rounded-full object-cover">
                            <div>
                                <div class="font-medium text-primary">${document.getElementById('details-student-name').textContent}</div>
                                <div class="text-secondary">${result.new_return.student_no}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 font-mono text-secondary">${result.new_return.return_id}</td>
                    <td class="px-6 py-4 text-secondary">${returnDate}</td>
                    <td class="px-6 py-4"><span class='px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${penaltyClass}'>${penaltyFormatted}</span></td>
                    <td class="px-6 py-4 text-right">
                        <button data-return-id='${result.new_return._id}' class='delete-return-btn p-2 text-secondary/60 hover:bg-red-100 hover:text-red-600 rounded-md transition-colors' title='Delete Record'><i data-lucide='trash-2' class='w-4 h-4'></i></button>
                    </td>
                `;
                tableBody.prepend(newRow);
                lucide.createIcons();
                
            } else {
                showStatusMessage('error', result.message || 'An unknown error occurred on the server.');
            }

        } catch (error) {
            showStatusMessage('error', error.message || 'An unexpected network error occurred.');
        } finally {
            confirmBtn.innerHTML = '<i data-lucide="check-circle"></i> Confirm Return';
            confirmBtn.disabled = false;
            lucide.createIcons();
        }
    });

    // --- ★★★ FIX 5: THE JAVASCRIPT DELETE FUNCTION ★★★ ---
    const returnHistoryTable = document.getElementById('return-history-table');

    returnHistoryTable.addEventListener('click', async (event) => {
        const deleteButton = event.target.closest('.delete-return-btn');
        
        if (!deleteButton) {
            return; // Exit if the click was not on a delete button
        }

        const returnId = deleteButton.dataset.returnId;
        
        if (!confirm('Are you sure you want to permanently delete this return record? This action cannot be undone.')) {
            return;
        }

        // Show loading state on the button
        deleteButton.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>';
        deleteButton.disabled = true;
        lucide.createIcons(); 

        try {
            const response = await fetch('api_delete_return.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ id: returnId })
            });

            const result = await response.json();

            if (result.status === 'success') {
                showStatusMessage('success', result.message);
                
                // This now correctly finds the parent <tr> of the button
                const rowToDelete = deleteButton.closest('tr');

                if(rowToDelete) {
                    rowToDelete.style.transition = 'opacity 0.3s ease-out';
                    rowToDelete.style.opacity = '0';
                    setTimeout(() => rowToDelete.remove(), 300);
                }
            } else {
                showStatusMessage('error', result.message || 'An unknown error occurred during deletion.');
                // Restore button on failure
                deleteButton.innerHTML = '<i data-lucide="trash-2" class="w-4 h-4"></i>';
                deleteButton.disabled = false;
                lucide.createIcons();
            }

        } catch (error) {
            showStatusMessage('error', 'A network error occurred. Could not delete the record.');
            // Restore button on network error
            deleteButton.innerHTML = '<i data-lucide="trash-2" class="w-4 h-4"></i>';
            deleteButton.disabled = false;
            lucide.createIcons();
        }
    });


    // --- (Filtering logic remains the same) ---
    const filterForm = document.getElementById('history-filter-form');
    const tableBody = document.getElementById('return-history-tbody');
    const exportBtn = document.getElementById('export-excel-btn');
    const resetBtn = document.getElementById('reset-btn');
    let debounceTimer;

    const applyFilters = async () => {
        tableBody.style.opacity = '0.5';
        const formData = new FormData(filterForm);
        const params = new URLSearchParams(formData);
        params.append('ajax', '1');
        try {
            const response = await fetch(`return.php?${params.toString()}`);
            const newHtml = await response.text();
            tableBody.innerHTML = newHtml;
            lucide.createIcons();
        } catch (error) {
            console.error('Error fetching filtered history:', error);
            tableBody.innerHTML = '<tr><td colspan="6" class="text-center py-10 text-red-500">Error loading data.</td></tr>';
        } finally {
            tableBody.style.opacity = '1';
        }
    };

    const updateExportLink = () => {
        const formData = new FormData(filterForm);
        const params = new URLSearchParams(formData);
        params.append('export', 'excel');
        exportBtn.href = `return.php?${params.toString()}`;
    };

    filterForm.addEventListener('submit', (e) => {
        e.preventDefault();
        applyFilters();
        updateExportLink();
    });

    filterForm.addEventListener('input', (e) => {
        updateExportLink();
        if (e.target.name === 'search') {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(applyFilters, 500);
        } else {
            // This was the bug from the video, it's now correct
            // (it doesn't auto-filter on dropdown change)
        }
    });

    resetBtn.addEventListener('click', (e) => {
        e.preventDefault();
        filterForm.reset();
        applyFilters();
        updateExportLink();
    });

    updateExportLink();
});
</script>

<?php
require_once __DIR__ . '/templates/footer.php';
?>