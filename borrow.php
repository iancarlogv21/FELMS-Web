<?php
session_start();
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
// == EXCEL EXPORT LOGIC (CORRECTED)
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    
    // **FIX**: All filtering logic MUST be duplicated inside the export block
    // to ensure it has the data it needs to create the report.

    // Get all filter values from the URL
    $filter_search = trim($_GET['search'] ?? '');
    $filter_program = trim($_GET['program'] ?? '');
    $filter_status = trim($_GET['status'] ?? '');
    $date_filter = trim($_GET['date_filter'] ?? 'all');

    // This will hold our final MongoDB query
    $filter = [];
    $today = new DateTime('now', new DateTimeZone('Asia/Manila'));

    
$pre_filter_pipeline = [
    // 1. Sort by creation date (latest first)
    ['$sort' => ['created_at' => -1]],
    
    // 2. Lookup Student Details
    ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
    
    // 3. Lookup Book Details (CRITICAL FIX: Join by book_identifier)
    ['$lookup' => [
        'from' => 'AddBook',
        'let' => ['id' => '$book_identifier'],
        'pipeline' => [
            ['$match' => [
                '$expr' => ['$or' => [
                    ['$eq' => ['$$id', '$isbn']],
                    ['$eq' => ['$$id', '$accession_number']]
                ]]
            ]],
            ['$limit' => 1] 
        ],
        'as' => 'book_details_array' // Use a temporary array name
    ]],
    
    // 4. Project and Promote Fields to Root Level
    ['$project' => [
        '_id' => 1, 'borrow_id' => 1, 'book_identifier' => 1, 'student_no' => 1,
        'borrow_date' => 1, 'due_date' => 1, 'return_date' => 1, 'penalty' => 1, 'created_at' => 1,
        'student_details' => 1,
        
        // Promote top-level fields for display and filtering
        'title' => ['$arrayElemAt' => ['$book_details_array.title', 0]],
        'thumbnail' => ['$arrayElemAt' => ['$book_details_array.thumbnail', 0]],
        'cover_url' => ['$arrayElemAt' => ['$book_details_array.cover_url', 0]],
        'isbn' => ['$arrayElemAt' => ['$book_details_array.isbn', 0]],
        'accession_number' => ['$arrayElemAt' => ['$book_details_array.accession_number', 0]],
        
        // Keep book_details object for the final unwind and fallback
        'book_details' => ['$arrayElemAt' => ['$book_details_array', 0]] 
    ]],

    // 5. Unwind Student Details
    ['$unwind' => ['path' => '$student_details', 'preserveNullAndEmptyArrays' => true]],
    
    // 6. CRITICAL FIX: Unwind Book Details for final structure compatibility
    ['$unwind' => ['path' => '$book_details', 'preserveNullAndEmptyArrays' => true]]
];

 
if (!empty($filter_search)) {
    $filter['$or'] = [
        // ✅ FIX: Use the promoted top-level 'title' field
        ['title' => ['$regex' => $filter_search, '$options' => 'i']], 
        
        ['student_details.first_name' => ['$regex' => $filter_search, '$options' => 'i']],
        ['student_details.last_name' => ['$regex' => $filter_search, '$options' => 'i']],
        ['student_no' => ['$regex' => $filter_search, '$options' => 'i']],
        ['borrow_id' => ['$regex' => $filter_search, '$options' => 'i']],
    ];
}

    // Add program filter (if a program is selected)
    if (!empty($filter_program)) {
        $filter['student_details.program'] = $filter_program;
    }

    // Add status filter (if a status is selected)
    if ($filter_status === 'borrowed') {
        $filter['return_date'] = null;
    } elseif ($filter_status === 'returned') {
        $filter['return_date'] = ['$ne' => null];
    } elseif ($filter_status === 'overdue') {
        $filter['return_date'] = null;
        $filter['due_date'] = ['$lt' => $today->format('Y-m-d')];
    }
    
    // --- End of duplicated logic ---

    $dbInstance = Database::getInstance();
    $borrowCollection = $dbInstance->borrows();
    
    $pipeline = $pre_filter_pipeline;

    if (!empty($filter)) {
        $pipeline[] = ['$match' => $filter];
    }
    
    $cursor = $borrowCollection->aggregate($pipeline);
    $borrow_history_for_export = iterator_to_array($cursor);

    // --- Start Building the Excel File ---
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $today_for_report = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $titleStyle = [ 'font' => ['bold' => true, 'size' => 18], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER] ];
    $subtitleStyle = [ 'font' => ['italic' => true, 'size' => 10], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER] ];
    $headerStyle = [ 'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4A5568']] ];

    $sheet->mergeCells('A1:H1');
    $sheet->setCellValue('A1', 'Transaction History Report');
    $sheet->getStyle('A1')->applyFromArray($titleStyle);
    
    $sheet->mergeCells('A2:H2');
    $sheet->setCellValue('A2', 'Generated on: ' . $today_for_report->format('Y-m-d H:i:s'));
    $sheet->getStyle('A2')->applyFromArray($subtitleStyle);

    $headers = ['Book Title', 'Student Name', 'Student No', 'Borrow Date', 'Due Date', 'Return Date', 'Status', 'Penalty'];
    $sheet->fromArray($headers, NULL, 'A4');
    $sheet->getStyle('A4:H4')->applyFromArray($headerStyle);

    $row = 5;
    foreach ($borrow_history_for_export as $tx) {
        $status = 'Borrowed';
        if (!empty($tx['return_date'])) {
            $status = 'Returned';
        } elseif (new DateTime($tx['due_date']) < new DateTime('now', new DateTimeZone('Asia/Manila'))) {
            $status = 'Overdue';
        }

        $penalty = 0;
        if (!empty($tx['return_date'])) {
            $penalty = $tx['penalty'] ?? 0;
        } else {
            try {
                $due_date = new DateTimeImmutable($tx['due_date']);
                $today_immutable = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
                if ($today_immutable > $due_date) {
                    $days_overdue = $today_immutable->diff($due_date)->days;
                    $penalty = $days_overdue * 10;
                }
            } catch (Exception $e) { /* Ignore date errors */ }
        }
        
        $sheet->setCellValue('A' . $row, $tx['book_details']['title'] ?? 'N/A');
        $sheet->setCellValue('B' . $row, ($tx['student_details']['first_name'] ?? '') . ' ' . ($tx['student_details']['last_name'] ?? 'N/A'));
        $sheet->setCellValue('C' . $row, $tx['student_no'] ?? 'N/A');
        $sheet->setCellValue('D' . $row, (new DateTime($tx['borrow_date']))->format('Y-m-d'));
        $sheet->setCellValue('E' . $row, (new DateTime($tx['due_date']))->format('Y-m-d'));
        $sheet->setCellValue('F' . $row, !empty($tx['return_date']) ? (new DateTime($tx['return_date']))->format('Y-m-d') : 'N/A');
        $sheet->setCellValue('G' . $row, $status);
        $sheet->setCellValue('H' . $row, $penalty);
        $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');
        $row++;
    }

    foreach (range('A', 'H') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }
    
    $filename = 'FELMS_Transaction_History_' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}



// 1. SET PAGE-SPECIFIC VARIABLES
$currentPage = 'borrow';
$pageTitle = 'Issue a Book - FELMS';




// --- UNIFIED FILTERING LOGIC ---
$dbInstance = Database::getInstance();

// Get distinct values for dropdowns
$programs = $dbInstance->students()->distinct('program', ['program' => ['$ne' => null, '$ne' => '']]);
sort($programs);

// Get all filter values from the URL
$filter_search = trim($_GET['search'] ?? '');
$filter_program = trim($_GET['program'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$date_filter = trim($_GET['date_filter'] ?? 'all');

// This will hold our final MongoDB query
$filter = [];
$today = new DateTime('now', new DateTimeZone('Asia/Manila'));

$pre_filter_pipeline = [
    ['$sort' => ['created_at' => -1]],
    ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
    ['$lookup' => ['from' => 'AddBook', 'localField' => 'isbn', 'foreignField' => 'isbn', 'as' => 'book_details']],
    ['$unwind' => ['path' => '$student_details', 'preserveNullAndEmptyArrays' => true]],
    ['$unwind' => ['path' => '$book_details', 'preserveNullAndEmptyArrays' => true]]
];

// Add search filter (if a search term is provided)
if (!empty($filter_search)) {
    $filter['$or'] = [
        ['book_details.title' => ['$regex' => $filter_search, '$options' => 'i']],
        ['student_details.first_name' => ['$regex' => $filter_search, '$options' => 'i']],
        ['student_details.last_name' => ['$regex' => $filter_search, '$options' => 'i']],
        ['student_no' => ['$regex' => $filter_search, '$options' => 'i']],
        ['borrow_id' => ['$regex' => $filter_search, '$options' => 'i']],
    ];
}

// Add program filter (if a program is selected)
if (!empty($filter_program)) {
    $filter['student_details.program'] = $filter_program;
}

// Add status filter (if a status is selected)
if ($filter_status === 'borrowed') {
    $filter['return_date'] = null;
} elseif ($filter_status === 'returned') {
    $filter['return_date'] = ['$ne' => null];
} elseif ($filter_status === 'overdue') {
    $filter['return_date'] = null;
    $filter['due_date'] = ['$lt' => $today->format('Y-m-d')];
}

// Add date range filter (if a date range is selected)
if ($date_filter !== 'all') {
    $startDate = null;
    $endDate = null;

    switch ($date_filter) {
        case 'today':
            $startDate = (clone $today)->setTime(0, 0, 0);
            $endDate = (clone $today)->setTime(23, 59, 59);
            break;
        case 'this_week':
            $startDate = (clone $today)->modify('monday this week')->setTime(0, 0, 0);
            $endDate = (clone $startDate)->modify('sunday this week')->setTime(23, 59, 59);
            break;
        case 'this_month':
            $startDate = (clone $today)->modify('first day of this month')->setTime(0, 0, 0);
            $endDate = (clone $today)->modify('last day of this month')->setTime(23, 59, 59);
            break;
    }

    if ($startDate && $endDate) {
        $filter['borrow_date'] = [
            '$gte' => $startDate->format('Y-m-d H:i:s'),
            '$lte' => $endDate->format('Y-m-d H:i:s')
        ];
    }
}

$borrow_history = [];
try {
    $dbInstance = Database::getInstance();
    $borrowCollection = $dbInstance->borrows();
    
    // The pipeline now starts with the pre-filter stages (lookups)
    $pipeline = $pre_filter_pipeline;

    // Then, if any filters exist, we add the $match stage
    if (!empty($filter)) {
        $pipeline[] = ['$match' => $filter];
    }
    
    $cursor = $borrowCollection->aggregate($pipeline);

    foreach ($cursor as $doc) {
        $borrow_history[] = $doc;
    }
} catch (Exception $e) {
    $history_error = "Could not fetch transaction history: " . $e->getMessage();
}

// 2. INCLUDE THE HEADER
require_once __DIR__ . '/templates/header.php';

// 3. INCLUDE THE SIDEBAR
require_once __DIR__ . '/templates/sidebar.php';
?>

<style>
    .form-label { display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500; color: #475569; }
    .form-input { display: block; width: 100%; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.65rem 0.85rem; background-color: #fff; transition: all 0.2s; }
    .form-input-with-icon { padding-left: 2.5rem; }
    .form-input:focus { border-color: #0ea5e9; box-shadow: 0 0 0 2px rgba(14, 165, 233, 0.4); outline: none; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.65rem 1rem; border-radius: 0.5rem; font-weight: 600; transition: all 0.2s; cursor: pointer; border: none; }
    .btn-primary { background-color: #0ea5e9; color: white; }
    .btn-primary:hover:not(:disabled) { background-color: #0284c7; }
    .btn:disabled { background-color: #e2e8f0; color: #94a3b8; cursor: not-allowed; }
    #receipt-content { font-family: 'Source Code Pro', monospace; }

    @media print {
    /* Hide everything on the page by default */
    body * {
        visibility: hidden;
    }
    /* Make only the receipt modal and its contents visible */
    #receipt-modal, #receipt-modal * {
        visibility: visible;
    }
    /* Position the modal at the top-left of the print page */
    #receipt-modal {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        display: block; /* Override flex centering for print */
    }
    /* Remove shadows and borders for a clean print */
    #receipt-modal-box {
        box-shadow: none;
        border: none;
        width: 100%;
        max-width: 100%;
        height: auto;
    }
    /* Ensure the receipt content itself has a white background */
    #receipt-content {
        background-color: white;
        color: black; /* Force black text */
    }
    /* Hide the control buttons (Download, Print, Close) */
    #receipt-controls {
        display: none;
    }
    /* Force Tailwind's background colors to print */
    .bg-red-50 {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}

/* Add this new class at the end of your <style> block */
        .search-input {
            padding-left: 2.70rem !important; /* Forces the correct padding */
        }

        .form-label { display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500; color: #475569; }

        
</style>

<main id="main-content" class="flex-1 p-6 md:p-10 overflow-y-auto">
    <header class="mb-8">
    <h1 class="text-4xl font-bold tracking-tight">Issue a Book</h1>
    
    <p class="text-secondary mt-2">Scan or enter details to complete a borrow transaction.</p>
    
</header>

    <div id="status-message"></div>

    <div class="bg-card p-8 rounded-2xl border border-theme shadow-sm mb-12">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-1">
            <form id="borrowForm" class="space-y-6">
                <div>
                    <label for="isbn_input" class="block mb-1 text-sm font-medium">Book ISBN</label>
                    <div class="relative">
                        <i data-lucide="scan-barcode" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-secondary"></i>
                        <input type="text" name="isbn" id="isbn_input" class="form-input search-input-fix" placeholder="Scan or type ISBN...">
                    </div>
                </div>
                <div>
                    <label for="student_no_input" class="block mb-1 text-sm font-medium">Student No.</label>
                    <div class="relative">
                        <i data-lucide="scan-face" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-secondary"></i>
                        <input type="text" name="student_no" id="student_no_input" class="form-input search-input-fix" placeholder="Scan or type student number...">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="borrow_date" class="block mb-1 text-sm font-medium">Borrow Date</label>
                        <input type="datetime-local" name="borrow_date" id="borrow_date" class="form-input">
                    </div>
                    <div>
    <label for="due_date" class="block mb-1 text-sm font-medium">Due Date</label>
    <input type="datetime-local" name="due_date" id="due_date" class="form-input">
</div>
                </div>
                <div class="pt-4 border-t border-theme">
                    <button type="submit" id="issueBtn" class="btn btn-primary w-full text-base py-3" disabled>
                        <i data-lucide="check-circle"></i> Issue Book
                    </button>
                </div>
            </form>
        </div>

        <div class="lg:col-span-2 space-y-6">
            <div id="book-preview" class="bg-[var(--bg-color)] p-6 rounded-xl border border-theme min-h-[220px] flex items-center justify-center">
                <div class="text-center text-secondary">
                    <i data-lucide="library" class="w-12 h-12 mx-auto"></i>
                    <p class="mt-2 font-medium">Scan a book's ISBN to see details here</p>
                </div>
            </div>
            <div id="student-preview" class="bg-[var(--bg-color)] p-6 rounded-xl border border-theme min-h-[220px] flex items-center justify-center">
                <div class="text-center text-secondary">
                    <i data-lucide="user-search" class="w-12 h-12 mx-auto"></i>
                    <p class="mt-2 font-medium">Enter a student number to see details here</p>
                </div>
            </div>
        </div>
    </div>
</div>

    <div class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">
    <h2 class="text-3xl font-bold">Transaction History</h2>
    <a href="borrow.php?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn bg-green-600 text-white hover:bg-green-200 border border-green-200"><i data-lucide="file-spreadsheet"></i>Export Excel</a>
</div>

<div class="bg-card p-6 rounded-2xl border border-theme shadow-sm mb-8">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="lg:col-span-2">
            <label for="search" class="block mb-1 text-sm font-medium">Search by Name/ID/Title</label>
            <div class="relative">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
               <input type="text" name="search" id="search" placeholder="Student, Book Title, Borrow ID..." class="form-input search-input" value="<?= htmlspecialchars($filter_search) ?>">
            </div>
        </div>
        <div>
            <label for="program-filter" class="block mb-1 text-sm font-medium">Program</label>
            <select name="program" id="program-filter" class="form-input">
                <option value="">All Programs</option>
                <?php foreach($programs as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= ($filter_program == $p) ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="status-filter" class="block mb-1 text-sm font-medium">Status</label>
            <select name="status" id="status-filter" class="form-input">
                <option value="">All Statuses</option>
                <option value="borrowed" <?= ($filter_status == 'borrowed') ? 'selected' : '' ?>>Borrowed</option>
                <option value="returned" <?= ($filter_status == 'returned') ? 'selected' : '' ?>>Returned</option>
                <option value="overdue" <?= ($filter_status == 'overdue') ? 'selected' : '' ?>>Overdue</option>
            </select>
        </div>
        <div class="flex self-end gap-2">
            <button type="submit" class="btn btn-primary w-full"><i data-lucide="filter"></i>Filter</button>
            <a href="borrow.php" class="btn btn-secondary w-full"><i data-lucide="rotate-cw"></i>Reset</a>
        </div>
    </form>
</div>

   
    
       

<div class="bg-card rounded-lg border border-theme shadow-sm overflow-hidden">
    <div class="block w-full overflow-x-auto">
        <div class="max-h-[60vh] overflow-y-auto">
            <table class="w-full text-sm text-left text-[var(--text-secondary)]">
                <thead class="text-xs uppercase bg-[var(--border-color)] text-[var(--text-primary)] sticky top-0">
                    <tr>
                        <th scope="col" class="px-6 py-4">Book</th>
                        <th scope="col" class="px-6 py-4">Student</th>
                        <th scope="col" class="px-6 py-4">Dates</th>
                        <th scope="col" class="px-6 py-4">Status</th>
                        <th scope="col" class="px-6 py-4">Penalty</th>
                        <th scope="col" class="px-6 py-4 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="history-table-body" class="bg-card">
                    <?php if (isset($history_error)) : ?>
                        <tr><td colspan="6" class="px-6 py-12 text-center text-[var(--accent-color)]"><?php echo $history_error; ?></td></tr>
                    <?php elseif (empty($borrow_history)) : ?>
                        <tr><td colspan="6" class="px-6 py-12 text-center text-[var(--text-secondary)]">No transactions for the selected period.</td></tr>
                    <?php else : ?>
                        <?php foreach ($borrow_history as $tx) : 
                            // START: PHP penalty logic (remains the same)
                            $penalty = 0; 
                            if (!empty($tx['return_date'])) {
                                $penalty = $tx['penalty'] ?? 0;
                            } else {
                                try {
                                    $due_date = new DateTimeImmutable($tx['due_date']);
                                    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
                                    
                                    $due_date_start = $due_date->setTime(0, 0, 0);
                                    $today_start = $today->setTime(0, 0, 0);
                                    
                                    if ($today_start > $due_date_start) {
                                        $days_overdue = $today_start->diff($due_date_start)->days;
                                        $penalty = $days_overdue * 10;
                                    }
                                } catch (Exception $e) {
                                    $penalty = 0;
                                }
                            }
                            // END: PHP penalty logic
                        ?>
                            <tr class="bg-card border-b border-theme hover:bg-[var(--bg-color)]/50" data-borrow-id="<?php echo htmlspecialchars($tx['borrow_id'] ?? ''); ?>" data-mongo-id="<?php echo htmlspecialchars((string)$tx['_id']); ?>" data-student-email="<?php echo htmlspecialchars($tx['student_details']['email'] ?? ''); ?>">
                            

<td class="px-6 py-4">
    <div class="flex items-center gap-4">
        <img src="<?php echo htmlspecialchars(
            // 1. Prioritize the cover URL saved in the borrow record itself
            $tx['cover_url'] 
            // 2. Fallback to the thumbnail saved in the borrow record itself
            ?? $tx['thumbnail'] 
            // 3. Fallback to the thumbnail from the joined AddBook object
            ?? $tx['book_details']['thumbnail'] 
            // 4. Final placeholder
            ?? 'https://placehold.co/80x120/f1f5f9/475569?text=N/A'
        ); ?>" class="w-12 h-16 object-cover rounded-md shadow-sm">
        <div>
            <div class="font-semibold text-[var(--text-primary)] text-base" data-receipt-title>
                <?php echo htmlspecialchars(
                    $tx['title'] 
                    ?? $tx['book_details']['title']
                    ?? 'Unknown Book'
                ); ?>
            </div>
            <?php 
                // Determine the most accurate identifier to display
                $displayIdentifier = $tx['book_identifier'] ?? $tx['isbn'] ?? $tx['accession_number'] ?? 'N/A';
                $identifierType = (strpos($displayIdentifier, '978') === 0 || strpos($displayIdentifier, '979') === 0) ? 'ISBN' : 'Accession No';
            ?>
            <div class="font-mono text-xs text-[var(--text-secondary)]">
                <?= $identifierType ?>: <?php echo htmlspecialchars($displayIdentifier); ?>
            </div>
        </div>
    </div>
</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <img src="<?php echo htmlspecialchars(getStudentPhotoUrl($tx['student_details'] ?? null)); ?>" class="w-10 h-10 object-cover rounded-full">
                                        <div>
                                            <div class="font-semibold text-[var(--text-primary)]" data-receipt-student-name><?php echo htmlspecialchars(($tx['student_details']['first_name'] ?? '') . ' ' . ($tx['student_details']['last_name'] ?? 'Unknown Student')); ?></div>
                                            <div class="text-xs text-[var(--text-secondary)]" data-receipt-student-no><?php echo htmlspecialchars($tx['student_no'] ?? 'N/A'); ?></div>
                                            <div class="font-mono text-xs text-[var(--text-secondary)] mt-1">Transaction ID: <?php echo htmlspecialchars($tx['borrow_id'] ?? 'N/A'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-xs">
                                    <div data-receipt-borrow-date><span class="font-semibold text-[var(--text-primary)]">Borrowed:</span> <?php echo (new DateTime($tx['borrow_date']))->format('M d, Y'); ?></div>
                                    <div data-receipt-due-date><span class="font-semibold text-red-600">Due:</span> <span class="text-red-600"><?php echo (new DateTime($tx['due_date']))->format('M d, Y'); ?></span></div>
                                </td>
                                <td class="px-6 py-4">
    <?php if (isset($tx['return_date']) && $tx['return_date']) : ?>
        <span class="inline-flex items-center gap-1.5 py-1 px-2.5 rounded-full text-xs font-medium bg-green-500 text-white shadow-md">Returned</span>
    <?php else : ?>
        <span class="inline-flex items-center gap-1.5 py-1 px-2.5 rounded-full text-xs font-medium bg-amber-500 text-white shadow-md">Borrowed</span>
    <?php endif; ?>
</td>
                                <td class="px-6 py-4 **text-right**">
    <span class='inline-flex text-sm **font-bold**' style="
        <?php if ($penalty > 0) : ?>
            /* Penalty > ₱0.00: Bold Red Text */
            color: #ef4444; /* Tailwind red-500 */
        <?php else : ?>
            /* Penalty = ₱0.00: Bold Neutral Text */
            color: #64748b; /* Tailwind slate-500 (Neutral Gray) */
        <?php endif; ?>
    ">₱<?php echo number_format($penalty, 2); ?></span>
</td>
                                <td class="px-6 py-4">
                                    <div class="flex justify-center items-center gap-2">
                                        <button onclick="showHistoryReceipt(this.closest('tr'))" class="p-2 text-[var(--text-secondary)] rounded-md hover:bg-[var(--border-color)] hover:text-[var(--text-primary)] transition-colors" title="View Receipt"><i data-lucide="receipt" class="w-4 h-4"></i></button>
                                        <button onclick="sendManualReceipt('<?php echo (string)$tx['_id']; ?>', '<?php echo htmlspecialchars($tx['student_details']['email'] ?? ''); ?>', this)" class="p-2 text-[var(--text-secondary)] rounded-md hover:bg-sky-100 hover:text-sky-700 transition-colors dark:hover:bg-sky-900/50" <?php echo empty($tx['student_details']['email']) ? 'disabled' : ''; ?> title="<?php echo empty($tx['student_details']['email']) ? 'No Student Email Available' : 'Send Email Receipt'; ?>"><i data-lucide="mail" class="w-4 h-4"></i></button>
                                        <button onclick="deleteTransaction('<?php echo (string)$tx['_id']; ?>')" class="p-2 text-[var(--text-secondary)] rounded-md hover:bg-red-100 hover:text-red-700 transition-colors dark:hover:bg-red-900/50" title="Delete Transaction"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div id="receipt-modal" class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center p-4 hidden z-50">
    <div id="receipt-modal-box" class="bg-white rounded-lg shadow-xl w-full max-w-sm">
        <div id="receipt-content" class="p-8 text-slate-800">
            <div class="text-center mb-6">
                <h2 class="text-2xl font-bold tracking-wider">LMS Transaction</h2>
                <p class="text-sm text-slate-500 tracking-widest">LIBRARY BORROW RECEIPT</p>
            </div>
            <div class="space-y-3 text-sm my-6">
                <div class="flex justify-between">
                    <span class="text-slate-500">Borrow ID:</span> 
                    <strong id="receipt-borrow-id" class="font-mono"></strong>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Student:</span> 
                    <strong id="receipt-student-name"></strong>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Student No:</span> 
                    <strong id="receipt-student-no" class="font-mono"></strong>
                </div>
            </div>
            <div class="border-t border-b border-dashed border-slate-300 py-4">
                <p class="text-xs text-slate-400 uppercase tracking-widest mb-2">Item Borrowed</p>
                <p id="receipt-title" class="text-lg font-bold leading-tight"></p>
            </div>
            <div class="py-4 space-y-3 text-sm">
                <div class="flex justify-between">
                    <span class="text-slate-500">Borrow Date:</span> 
                    <span id="receipt-borrow-date"></span>
                </div>
                <div class="flex justify-between items-center text-base font-bold p-2 bg-red-50 rounded-md">
                    <span class="text-red-600">DUE DATE:</span> 
                    <strong id="receipt-due-date" class="text-red-600"></strong>
                </div>
            </div>
            <div class="mt-6 flex flex-col items-center">
                <svg id="barcode"></svg>
                <p class="text-xs text-slate-500 mt-2">Present this for book return</p>
            </div>
            <p class="text-center text-xs text-slate-400 mt-6">Thank you for using the library.</p>
        </div>

        <div id="receipt-controls" class="bg-slate-50 p-4 grid grid-cols-3 gap-3 rounded-b-lg border-t">
            <button id="save-receipt-btn" class="btn bg-green-100 text-green-800 hover:bg-green-200 w-full"><i data-lucide="download"></i>Download</button>
            <button onclick="window.print()" class="btn bg-slate-200 text-slate-800 hover:bg-slate-300 w-full"><i data-lucide="printer"></i>Print</button>
            <button onclick="closeModal()" class="btn bg-sky-100 text-sky-800 hover:bg-sky-200 w-full"><i data-lucide="x"></i>Close</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
   

        // --- NEW AND IMPROVED SCRIPT ---
        const bookPreview = document.getElementById('book-preview');
        const studentPreview = document.getElementById('student-preview');

        // HTML Templates for dynamic content
        const bookSkeleton = `<div class="w-full animate-pulse flex items-start gap-6"><div class="w-28 h-40 bg-slate-200 rounded-lg flex-shrink-0"></div><div class="flex-1 space-y-3 pt-2"><div class="h-5 bg-slate-200 rounded-md w-5/6"></div><div class="h-3 bg-slate-200 rounded-md w-1/2"></div><div class="h-3 bg-slate-200 rounded-md w-2/3 mt-4"></div><div class="h-3 bg-slate-200 rounded-md w-1/3"></div><div class="h-8 bg-slate-200 rounded-full w-32 mt-4"></div></div></div>`;
        const studentSkeleton = `<div class="w-full animate-pulse flex items-center gap-6"><div class="w-24 h-24 bg-slate-200 rounded-full flex-shrink-0"></div><div class="flex-1 space-y-3"><div class="h-5 bg-slate-200 rounded-md w-3/4"></div><div class="h-3 bg-slate-200 rounded-md w-1/2"></div><div class="h-3 bg-slate-200 rounded-md w-2/3 mt-4"></div><div class="h-3 bg-slate-200 rounded-md w-1/3"></div><div class="h-8 bg-slate-200 rounded-full w-36 mt-4"></div></div></div>`;
        
        const bookLoadedHTML = (book) => {
            // 1. Determine which identifier to display (ACC No. or ISBN)
            const hasAccession = book.accession_number && book.accession_number !== '';
            
            const identifierText = hasAccession 
                ? `ACC No: ${book.accession_number}` 
                : `ISBN: ${book.isbn||'N/A'}`;
                
            const identifierIcon = hasAccession ? 'scan' : 'hash'; 
            
            // 2. Define colors for all icons (Enhanced Colors)
            const iconColors = {
                identifier: hasAccession ? 'text-red-600' : 'text-blue-600', // Red for ACC, Blue for ISBN
                publisher: 'text-amber-600',
                published: 'text-purple-600',
            };
            
            return `<div class="w-full flex items-start gap-6 text-left">
                <img src="${book.thumbnail||'https://placehold.co/120x160/f1f5f9/475569?text=N/A'}" class="w-28 h-40 object-cover rounded-lg shadow-md flex-shrink-0" alt="Book Cover">
                <div class="flex-1">
                    <h3 class="text-xl font-bold">${book.title||'Unknown Title'}</h3>
                    <p class="text-slate-600 text-sm">by ${Array.isArray(book.authors)?book.authors.join(', '):(book.authors||'Unknown Author')}</p>
                    <div class="mt-4 space-y-2 text-sm text-slate-500">
                        
                        <div class="flex items-center gap-2">
                            <i data-lucide="${identifierIcon}" class="w-4 h-4 ${iconColors.identifier}"></i>
                            <span>${identifierText}</span>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <i data-lucide="building" class="w-4 h-4 ${iconColors.publisher}"></i>
                            <span>${book.publisher||'Unknown Publisher'}</span>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <i data-lucide="calendar" class="w-4 h-4 ${iconColors.published}"></i>
                            <span>Published on ${book.published_date||'N/A'}</span>
                        </div>
                        
                    </div>
                    <div class="mt-4 py-1.5 px-3.5 text-sm font-semibold rounded-full inline-flex items-center gap-2 ${book.quantity>0?'bg-green-100 text-green-800':'bg-red-100 text-red-800'}">
                        <i data-lucide="${book.quantity>0?'check-circle':'x-circle'}" class="w-4 h-4"></i>
                        ${book.quantity>0?`Available (Qty: ${book.quantity})`:'Not Available'}
                    </div>
                </div>
            </div>`;
        };
        
       // borrow.php (Around line 538 - Replace the original studentLoadedHTML constant)

        const studentLoadedHTML = (student, borrowCount) => {
            // Define icon colors for the student details
            const iconColors = {
                mail: 'text-sky-600', // Blue for email
                program: 'text-teal-600', // Teal for program/graduation
                history: 'text-pink-600', // Pink for history
            };
            
            return `<div class="w-full flex items-center gap-6 text-left">
                <img src="${student.photoUrl}" class="w-24 h-24 object-cover rounded-full shadow-md flex-shrink-0" alt="Student Photo">
                <div class="flex-1">
                    <h3 class="text-xl font-bold">${student.first_name||''} ${student.last_name||'Unknown Student'}</h3>
                    <p class="text-slate-500 text-sm font-mono">${student.student_no||'N/A'}</p>
                    <div class="mt-4 space-y-2 text-sm text-slate-500">
                        
                        <div class="flex items-center gap-2">
                            <i data-lucide="mail" class="w-4 h-4 ${iconColors.mail}"></i>
                            <span>${student.email||'No email on file'}</span>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <i data-lucide="graduation-cap" class="w-4 h-4 ${iconColors.program}"></i>
                            <span>${student.program||'N/A Program'} - Year ${student.year||'N/A'}</span>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <i data-lucide="history" class="w-4 h-4 ${iconColors.history}"></i>
                            <span>Has borrowed ${borrowCount} time(s) previously.</span>
                        </div>
                        
                    </div>
                    <div class="mt-4 py-1.5 px-3.5 text-sm font-semibold rounded-full inline-flex items-center gap-2 ${student.is_eligible?'bg-green-100 text-green-800':'bg-amber-100 text-amber-800'}">
                        <i data-lucide="${student.is_eligible?'user-check':'user-x'}" class="w-4 h-4"></i>
                        ${student.is_eligible?'Eligible to Borrow':'Not Eligible (Limit Reached)'}
                    </div>
                </div>
            </div>`;
        };
        const notFoundHTML = (type, message) => `<div class="w-full text-center text-red-600"><i data-lucide="${type==='book'?'book-x':'user-x'}" class="w-12 h-12 text-red-400 mx-auto"></i><h3 class="mt-3 text-lg font-semibold text-red-700">${type==='book'?'Book Not Found':'Student Not Found'}</h3><p class="text-sm">${message}</p></div>`;
        
        // Default HTML content (restored from original working state)
        const defaultBookHTML = '<div class="text-center text-secondary"><i data-lucide="library" class="w-12 h-12 mx-auto"></i><p class="mt-2 font-medium">Scan a book\'s ISBN to see details here</p></div>';
        const defaultStudentHTML = '<div class="text-center text-secondary"><i data-lucide="user-search" class="w-12 h-12 mx-auto"></i><p class="mt-2 font-medium">Enter a student number to see details here</p></div>';
        

        // State management
        let validBook = null;
        let validStudent = null;
        const issueBtn = document.getElementById('issueBtn');
        const isbnInput = document.getElementById('isbn_input');
        const studentInput = document.getElementById('student_no_input');
        const borrowDateInput = document.getElementById('borrow_date');
        const dueDateInput = document.getElementById('due_date');

        const checkFormValidity = () => {
            issueBtn.disabled = !(validBook && validStudent && validStudent.is_eligible);
        };

       const setDefaultDateTime = () => {
            const now = new Date();
            
            // Manually build the string in the correct "YYYY-MM-DDTHH:MM" format from local time
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0'); // Month is 0-indexed
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            
            const localDateTimeString = `${year}-${month}-${day}T${hours}:${minutes}`;
            
            borrowDateInput.value = localDateTimeString;
            
            // The event listener will automatically set the correct due date
            borrowDateInput.dispatchEvent(new Event('change')); 
        };

        borrowDateInput.addEventListener('change', () => {
            if (borrowDateInput.value) {
                const borrowDate = new Date(borrowDateInput.value);
                const dueDate = new Date(borrowDate);
                dueDate.setDate(dueDate.getDate() + 7);
                
                // ✨ FIX: Changed format for datetime-local input
                dueDateInput.value = dueDate.toISOString().slice(0, 16);
            }
        });
        
        // Async fetch functions
        const fetchBookDetails = async (identifier) => {
            if (!identifier) {
                bookPreview.innerHTML = defaultBookHTML; // Use the default template
                validBook = null;
                lucide.createIcons();
                checkFormValidity();
                return;
            }
            bookPreview.innerHTML = bookSkeleton;
            validBook = null;
            
            const formData = new FormData();
            formData.append('action', 'fetch_book');
            formData.append('isbn', identifier); // 'isbn' field is used for both identifiers in PHP
            try {
                const response = await fetch('borrow_action.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    bookPreview.innerHTML = bookLoadedHTML(result.book);
                    if (result.book.quantity > 0) {
                        validBook = result.book;
                    }
                } else {
                    bookPreview.innerHTML = notFoundHTML('book', result.message);
                }
            } catch (error) {
                bookPreview.innerHTML = notFoundHTML('book', 'Error connecting to the server.');
            } finally {
                lucide.createIcons();
                checkFormValidity();
            }
        };

        const fetchStudentDetails = async (student_no) => {
            if (!student_no) {
                studentPreview.innerHTML = defaultStudentHTML; // Use the default template
                validStudent = null;
                lucide.createIcons();
                checkFormValidity();
                return;
            }
            studentPreview.innerHTML = studentSkeleton;
            validStudent = null;
            
            const formData = new FormData();
            formData.append('action', 'fetch_student');
            formData.append('student_no', student_no);
            try {
                const response = await fetch('borrow_action.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    studentPreview.innerHTML = studentLoadedHTML(result.student, result.borrow_count);
                    if (result.student.is_eligible) {
                        validStudent = result.student;
                    }
                } else {
                    studentPreview.innerHTML = notFoundHTML('student', result.message);
                }
            } catch (error) {
                studentPreview.innerHTML = notFoundHTML('student', 'Error connecting to the server.');
            } finally {
                lucide.createIcons();
                checkFormValidity();
            }
        };

        // Debounce helper to prevent excessive API calls
        const debouncedFetch = (func, delay) => {
            let timeout;
            return (...args) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => { func.apply(this, args); }, delay);
            };
        };

        isbnInput.addEventListener('input', debouncedFetch(() => fetchBookDetails(isbnInput.value.trim()), 500));
        studentInput.addEventListener('input', debouncedFetch(() => fetchStudentDetails(studentInput.value.trim()), 500));
        
        // Handle form submission
        document.getElementById('borrowForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            issueBtn.disabled = true;
            const originalButtonText = issueBtn.innerHTML;
            issueBtn.innerHTML = '<i data-lucide="loader-2" class="animate-spin"></i> Processing...';
            lucide.createIcons();

            const formData = new FormData(e.target);
            formData.append('action', 'save');
            
            try {
                const response = await fetch('borrow_action.php', { method: 'POST', body: formData });
                const result = await response.json();
                window.showStatus(result.message, !result.success);

                if (result.success && result.new_transaction) {
                    // Reset UI after successful transaction
                    e.target.reset();
                    setDefaultDateTime();
                    // Restore initial default content (using defined variables)
                    bookPreview.innerHTML = defaultBookHTML;
                    studentPreview.innerHTML = defaultStudentHTML;
                    lucide.createIcons();
                    validBook = null;
                    validStudent = null;
                    
                }
            } catch (error) {
                window.showStatus('An error occurred while submitting the form.', true);
            } finally {
                issueBtn.disabled = true; // Keep it disabled after submission
                issueBtn.innerHTML = originalButtonText;
                lucide.createIcons();
            }
        });
        
        setDefaultDateTime();


        async function refreshHistoryTable() {
            if (document.hidden) {
                return; 
            }
            try {
                const response = await fetch('ajax_refresh_history.php');
                if (!response.ok) return;

                const newTableBodyHTML = await response.text();
                const tableBody = document.getElementById('history-table-body');
                
                if (tableBody && tableBody.innerHTML !== newTableBodyHTML) {
                    tableBody.innerHTML = newTableBodyHTML;
                    lucide.createIcons();
                }
            } catch (error) {
                console.error("Failed to refresh history:", error);
            }
        }

        // Run the refresh function every 5 seconds
       // setInterval(refreshHistoryTable, 5000);
    });

    

    

    // Global helper functions
    window.showStatus = (message, isError = false) => {
        const statusDiv = document.getElementById('status-message');
        const colorClass = isError ? 'bg-red-100 border-red-500 text-red-800' : 'bg-green-100 border-green-500 text-green-800';
        statusDiv.innerHTML = `<div class="${colorClass} border-l-4 p-4 rounded-r-lg mb-6" role="alert"><p>${message}</p></div>`;
        window.scrollTo({ top: 0, behavior: 'smooth' });
        setTimeout(() => statusDiv.innerHTML = '', 5000);
    };

    window.sendManualReceipt = async (mongoId, email, buttonElement) => {
        if (!email) {
            window.showStatus('This student does not have an email address on file.', true);
            return;
        }
        const originalIcon = buttonElement.innerHTML;
        buttonElement.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>';
        buttonElement.disabled = true;
        lucide.createIcons();
        const formData = new FormData();
        formData.append('action', 'send_receipt');
        formData.append('_id', mongoId);
        try {
            const response = await fetch('borrow_action.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.success) {
                window.showStatus(result.message, false);
                buttonElement.innerHTML = '<i data-lucide="check" class="w-4 h-4 text-green-600"></i>';
            } else {
                window.showJustatus('Email Failed: ' + (result.message || 'Unknown server error'), true);
                buttonElement.innerHTML = '<i data-lucide="x" class="w-4 h-4 text-red-600"></i>';
            }
            lucide.createIcons();
        } catch (error) {
            window.showStatus('A network error occurred. Please check the PHP error logs.', true);
            buttonElement.innerHTML = '<i data-lucide="x" class="w-4 h-4 text-red-600"></i>';
            lucide.createIcons();
        } finally {
            setTimeout(() => {
                buttonElement.innerHTML = originalIcon;
                buttonElement.disabled = false;
            }, 3000);
        }
    };

    window.deleteTransaction = async (mongoId) => {
        if (!confirm('Are you sure you want to delete this transaction? This action cannot be undone.')) { return; }
        const rowToDelete = document.querySelector(`[data-mongo-id="${mongoId}"]`);
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('_id', mongoId);
        try {
            const response = await fetch('borrow_action.php', { method: 'POST', body: formData });
            const result = await response.json();
            window.showStatus(result.message, !result.success);
            if (result.success && rowToDelete) {
                rowToDelete.style.opacity = '0';
                setTimeout(() => rowToDelete.remove(), 500);
            } else if (result.success) {
                setTimeout(() => window.location.reload(), 1500);
            }
        } catch (error) {
            window.showStatus('A network error occurred.', true);
        }
    };

    const modal = document.getElementById('receipt-modal');

    window.showHistoryReceipt = (rowElement) => {
        
        const data = {
            title: rowElement.querySelector('[data-receipt-title]').textContent,
            student_name: rowElement.querySelector('[data-receipt-student-name]').textContent,
            student_no: rowElement.querySelector('[data-receipt-student-no]').textContent,
            
           
            borrow_date: rowElement.querySelector('[data-receipt-borrow-date]').textContent.replace('Borrowed:', '').trim(),
            due_date: rowElement.querySelector('[data-receipt-due-date]').textContent.replace('Due:', '').trim(),
            
            borrow_id: rowElement.dataset.borrowId,
        };
        data.barcode_data = data.borrow_id;
        showReceiptModal(data);
    };

    function showReceiptModal(data) {
   
    document.getElementById('receipt-borrow-id').textContent = data.borrow_id || 'N/A';
    document.getElementById('receipt-title').textContent = data.title || 'N/A';
    document.getElementById('receipt-student-name').textContent = data.student_name || 'N/A';
    document.getElementById('receipt-student-no').textContent = data.student_no || 'N/A';
    document.getElementById('receipt-borrow-date').textContent = data.borrow_date || 'N/A';
    document.getElementById('receipt-due-date').textContent = data.due_date || 'N/A';

    
    const barcodeData = data.barcode_data || '000000'; 
    
    
    JsBarcode("#barcode", barcodeData, {
        format: "CODE128",
        
        width: 1.5, 
       
        height: 50, 
        fontOptions: "bold",
        textMargin: 5,
        
        margin: 5
    });
    

    const saveBtn = document.getElementById('save-receipt-btn');
    saveBtn.onclick = () => saveReceipt(barcodeData);
    modal.classList.remove('hidden');
}

    async function saveReceipt(barcodeData) {
        const receiptElement = document.getElementById('receipt-content');
        const saveBtn = document.getElementById('save-receipt-btn');
        const originalBtnContent = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i data-lucide="loader-2" class="animate-spin"></i> Saving...';
        lucide.createIcons();
        try {
            const canvas = await html2canvas(receiptElement);
            const link = document.createElement('a');
            link.download = `lms-receipt-${barcodeData}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
        } catch (error) {
            console.error("Failed to save receipt:", error);
            window.showStatus('Error generating receipt image.', true);
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalBtnContent;
            lucide.createIcons();
        }
    }

    window.closeModal = () => {
        modal.classList.add('hidden');
    };


    
</script>
</body>
</html>
<?php
// INCLUDE THE FOOTER
require_once __DIR__ . '/templates/footer.php';
?>



