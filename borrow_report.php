<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$filter_status = trim($_GET['status'] ?? 'all');
$reportTitle = 'Borrowing Report';
$pageTitle = 'Borrowing Report - FELMS';
$filter = [];
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$penaltyRate = 10;

switch ($filter_status) {
    case 'borrowed':
        $reportTitle = 'Active Borrowings Report';
        $filter['return_date'] = null;
        break;
    case 'overdue':
        $reportTitle = 'Overdue Books Report';
        $filter['return_date'] = null;
        $filter['due_date'] = ['$lt' => $today->format('Y-m-d')];
        break;
    case 'penalties':
        $reportTitle = 'All Transactions with Penalties';
        $filter['$or'] = [
            ['penalty' => ['$gt' => 0]],
            ['return_date' => null, 'due_date' => ['$lt' => $today->format('Y-m-d')]]
        ];
        break;
}
$pageTitle = $reportTitle . ' - FELMS';

$report_data = [];
$db_error = null;
try {
    $dbInstance = Database::getInstance();
    $borrowCollection = $dbInstance->borrows();
    
    $pipeline = [];
    if (!empty($filter)) {
        $pipeline[] = ['$match' => $filter];
    }
    
    $pipeline = array_merge($pipeline, [
        ['$sort' => ['due_date' => 1]],
        ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
        ['$lookup' => ['from' => 'AddBook', 'localField' => 'isbn', 'foreignField' => 'isbn', 'as' => 'book_details']],
        ['$unwind' => ['path' => '$student_details', 'preserveNullAndEmptyArrays' => true]],
        ['$unwind' => ['path' => '$book_details', 'preserveNullAndEmptyArrays' => true]]
    ]);
    
    $cursor = $borrowCollection->aggregate($pipeline);
    $report_data = iterator_to_array($cursor);

} catch (Exception $e) {
    $db_error = "Could not fetch report data: " . $e->getMessage();
}

// --- Summary Totals Calculation ---
$total_records = count($report_data);
$total_penalty_sum = 0;

foreach ($report_data as $record) {
    if (!empty($record['return_date'])) {
        $total_penalty_sum += $record['penalty'] ?? 0;
    } else {
        try {
            $dueDate = new DateTimeImmutable($record['due_date']);
            if ($today > $dueDate) {
                $interval = $today->diff($dueDate);
                // THE FIX: Use format('%a') to get the total number of days.
                $daysOverdue = (int)$interval->format('%a');
                $total_penalty_sum += $daysOverdue * $penaltyRate;
            }
        } catch (Exception $e) {}
    }
}

// --- Handle CSV Export ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // This logic now also uses the corrected calculation
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . str_replace(' ', '_', $reportTitle) . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Book Title', 'Student Name', 'Borrow Date', 'Due Date', 'Return Date', 'Penalty']);

    foreach ($report_data as $record) {
        $penalty = 0;
        if (!empty($record['return_date'])) {
            $penalty = $record['penalty'] ?? 0;
        } else {
            try {
                $dueDate = new DateTimeImmutable($record['due_date']);
                if ($today > $dueDate) {
                    $interval = $today->diff($dueDate);
                    // THE FIX: Use format('%a') here as well.
                    $daysOverdue = (int)$interval->format('%a');
                    $penalty = $daysOverdue * $penaltyRate;
                }
            } catch(Exception $e) {}
        }
        fputcsv($output, [
            $record['book_details']['title'] ?? 'N/A',
            ($record['student_details']['first_name'] ?? '') . ' ' . ($record['student_details']['last_name'] ?? ''),
            $record['borrow_date'] ?? '',
            $record['due_date'] ?? '',
            $record['return_date'] ?? 'Not Returned',
            number_format($penalty, 2)
        ]);
    }
    fclose($output);
    exit;
}

require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/sidebar.php';
?>

<main id="main-content" class="flex-1 p-6 md:p-10">
    <header class="flex flex-col md:flex-row justify-between md:items-center mb-8">
        <div>
            <h1 class="text-4xl font-bold tracking-tight"><?= htmlspecialchars($reportTitle) ?></h1>
            <p class="text-secondary mt-2">A detailed list based on your selection from the dashboard.</p>
        </div>
        <a href="?status=<?= htmlspecialchars($filter_status) ?>&export=csv" class="btn bg-green-600 hover:bg-green-700 text-white mt-4 md:mt-0">
            <i data-lucide="download-cloud"></i> <span>Export to CSV</span>
        </a>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <div class="bg-card p-6 rounded-xl border border-theme flex items-center gap-4 shadow-sm">
            <div class="bg-blue-100 p-3 rounded-full"><i data-lucide="list" class="w-7 h-7 text-blue-600"></i></div>
            <div>
                <p class="text-secondary">Total Records Found</p>
                <p class="text-3xl font-bold"><?php echo number_format($total_records); ?></p>
            </div>
        </div>
        <div class="bg-card p-6 rounded-xl border border-theme flex items-center gap-4 shadow-sm">
            <div class="bg-orange-100 p-3 rounded-full"><i data-lucide="coins" class="w-7 h-7 text-orange-600"></i></div>
            <div>
                <p class="text-secondary">Sum of Penalties</p>
                <p class="text-3xl font-bold">₱<?php echo number_format($total_penalty_sum, 2); ?></p>
            </div>
        </div>
    </div>

    <div class="bg-card rounded-lg border border-theme shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-secondary uppercase bg-body sticky top-0">
                    <tr>
                        <th class="px-6 py-4">Book & Student</th>
                        <th class="px-6 py-4">Dates</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Penalty</th>
                    </tr>
                </thead>
                <tbody class="bg-card">
                    <?php if (empty($report_data)): ?>
                        <tr><td colspan="4" class="text-center py-12 text-secondary">No records with penalties found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($report_data as $record): ?>
                            <?php
                                $penalty = 0;
                                if (!empty($record['return_date'])) {
                                    $penalty = $record['penalty'] ?? 0;
                                } else {
                                    try {
                                        $dueDate = new DateTimeImmutable($record['due_date']);
                                        if ($today > $dueDate) {
                                            $interval = $today->diff($dueDate);
                                            // THE FIX: Use format('%a') for display as well.
                                            $daysOverdue = (int)$interval->format('%a');
                                            $penalty = $daysOverdue * $penaltyRate;
                                        }
                                    } catch (Exception $e) {}
                                }
                            ?>
                            <tr class="border-b border-theme hover:bg-body">
                                <td class="px-6 py-4">
                                    <div class="font-bold"><?= htmlspecialchars($record['book_details']['title'] ?? 'Book Not Found') ?></div>
                                    <div class="text-xs text-secondary">
                                        by: <?= htmlspecialchars(($record['student_details']['first_name'] ?? 'Student') . ' ' . ($record['student_details']['last_name'] ?? 'Not Found')) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-xs">
                                    <div><strong>Borrowed:</strong> <?= (new DateTime($record['borrow_date']))->format('M d, Y') ?></div>
                                    <div class="font-medium text-red-600"><strong>Due:</strong> <?= (new DateTime($record['due_date']))->format('M d, Y') ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if (!empty($record['return_date'])): ?>
                                        <div class="font-semibold">Returned</div>
                                        <div class="text-xs"><?= (new DateTime($record['return_date']))->format('M d, Y') ?></div>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">Currently Borrowed</span>
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