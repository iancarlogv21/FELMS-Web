<?php
// ajax_refresh_history.php (CORRECTED VERSION)
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

// Basic security check
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(403); // Forbidden
    exit;
}

try {
    $dbInstance = Database::getInstance();
    $borrowCollection = $dbInstance->borrows();
    
    $pipeline = [
        ['$sort' => ['created_at' => -1]],
        ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
        ['$lookup' => ['from' => 'AddBook', 'localField' => 'isbn', 'foreignField' => 'isbn', 'as' => 'book_details']],
        ['$unwind' => ['path' => '$student_details', 'preserveNullAndEmptyArrays' => true]],
        ['$unwind' => ['path' => '$book_details', 'preserveNullAndEmptyArrays' => true]]
    ];
    
    $cursor = $borrowCollection->aggregate($pipeline);
    $borrow_history = $cursor->toArray();

} catch (Exception $e) {
    exit;
}

if (empty($borrow_history)) {
    echo '<tr><td colspan="6" class="px-6 py-12 text-center text-slate-500">No transactions found.</td></tr>';
} else {
    foreach ($borrow_history as $tx) {
        $borrow_id = htmlspecialchars($tx['borrow_id'] ?? '');
        $mongo_id = htmlspecialchars((string)$tx['_id']);
        $student_email = htmlspecialchars($tx['student_details']['email'] ?? '');
        $email_disabled_attr = empty($student_email) ? 'disabled' : '';

        echo "<tr class='bg-white border-b hover:bg-slate-50/50' data-borrow-id='{$borrow_id}' data-mongo-id='{$mongo_id}' data-student-email='{$student_email}'>";
        
        $book_thumb = htmlspecialchars($tx['book_details']['thumbnail'] ?? 'https://placehold.co/80x120/f1f5f9/475569?text=N/A');
        $book_title = htmlspecialchars($tx['book_details']['title'] ?? ($tx['title'] ?? 'Unknown Book'));
        $book_isbn = htmlspecialchars($tx['isbn'] ?? 'N/A');
        echo "<td class='px-6 py-4'><div class='flex items-center gap-4'><img src='{$book_thumb}' class='w-12 h-16 object-cover rounded-md shadow-sm'><div><div class='font-semibold text-slate-800 text-base'>{$book_title}</div><div class='font-mono text-xs text-slate-400'>ISBN: {$book_isbn}</div></div></div></td>";

        $student_photo = htmlspecialchars(getStudentPhotoUrl($tx['student_details'] ?? null));
        $student_name = htmlspecialchars(($tx['student_details']['first_name'] ?? '') . ' ' . ($tx['student_details']['last_name'] ?? 'Unknown Student'));
        $student_no = htmlspecialchars($tx['student_no'] ?? 'N/A');
        echo "<td class='px-6 py-4'><div class='flex items-center gap-3'><img src='{$student_photo}' class='w-10 h-10 object-cover rounded-full'><div><div class='font-semibold text-slate-800'>{$student_name}</div><div class='text-xs text-slate-500'>{$student_no}</div><div class='font-mono text-xs text-slate-400 mt-1'>Receipt ID: {$borrow_id}</div></div></div></td>";

        $borrow_date = (new DateTime($tx['borrow_date']))->format('M d, Y');
        $due_date = (new DateTime($tx['due_date']))->format('M d, Y');
        echo "<td class='px-6 py-4 text-xs'><div><span class='font-semibold text-slate-600'>Borrowed:</span> {$borrow_date}</div><div><span class='font-semibold text-slate-600'>Due:</span> {$due_date}</div></td>";

        $status_cell = "<td class='px-6 py-4'>";
        if (isset($tx['return_date']) && $tx['return_date']) {
            $status_cell .= "<span class='inline-flex items-center gap-1.5 py-1 px-2.5 rounded-full text-xs font-medium bg-slate-100 text-slate-700'>Returned</span>";
        } else {
            $status_cell .= "<span class='inline-flex items-center gap-1.5 py-1 px-2.5 rounded-full text-xs font-medium bg-sky-100 text-sky-800'>Borrowed</span>";
        }
        $status_cell .= "</td>";
        echo $status_cell;

        // --- CORRECTED PENALTY LOGIC ---
        $penalty = 0;

        // If the book is returned, use the penalty value that was saved in the database.
        if (!empty($tx['return_date'])) {
            $penalty = $tx['penalty'] ?? 0;
        } 
        // Otherwise, if it's still borrowed, calculate the current overdue penalty.
        else {
            try {
                $due_date_obj = new DateTimeImmutable($tx['due_date']);
                $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
                $due_date_start = $due_date_obj->setTime(0, 0, 0);
                $today_start = $today->setTime(0, 0, 0);

                if ($today_start > $due_date_start) {
                    $days_overdue = $today_start->diff($due_date_start)->days;
                    $penalty_rate = 10;
                    $penalty = $days_overdue * $penalty_rate;
                }
            } catch (Exception $e) {
                // Keep penalty at 0 if there's a date error
            }
        }
        
        $penalty_class = $penalty > 0 ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800';
        $penalty_formatted = '₱' . number_format($penalty, 2);
        echo "<td class='px-6 py-4'><span class='px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full {$penalty_class}'>{$penalty_formatted}</span></td>";
        
        echo "<td class='px-6 py-4'><div class='flex justify-center items-center gap-2'>";
        echo "<div class='relative group'><button onclick='showHistoryReceipt(this.closest(\"tr\"))' class='p-2 text-slate-500 rounded-md hover:bg-slate-100 hover:text-slate-800 transition-colors'><i data-lucide='receipt' class='w-4 h-4'></i></button></div>";
        echo "<div class='relative group'><button onclick='sendManualReceipt(\"{$mongo_id}\", \"{$student_email}\", this)' class='p-2 text-slate-500 rounded-md hover:bg-sky-100 hover:text-sky-700 transition-colors' {$email_disabled_attr}><i data-lucide='mail' class='w-4 h-4'></i></button></div>";
        echo "<div class='relative group'><button onclick='deleteTransaction(\"{$mongo_id}\")' class='p-2 text-slate-500 rounded-md hover:bg-red-100 hover:text-red-700 transition-colors'><i data-lucide='trash-2' class='w-4 h-4'></i></button></div>";
        echo "</div></td>";

        echo "</tr>";
    }
}
?>