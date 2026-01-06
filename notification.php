<?php
$currentPage = 'notifications'; 
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$pageTitle = 'Notifications - FELMS';
$proactive_list = []; // NEW: For 2-day warning
$overdue_list = []; // EXISTING: For books that are already late
$returned_list = []; // NEW: For displaying recently returned books
$today = new DateTime('now', new DateTimeZone('Asia/Manila'));
$twoDaysFromNow = (clone $today)->modify('+2 days')->format('Y-m-d');

try {
    $dbInstance = Database::getInstance();
    $borrowCollection = $dbInstance->borrows(); // This is the 'borrow_book' collection

    // --- ★★★ FIX 1: Corrected Proactive Pipeline ★★★ ---
    $proactive_pipeline = [
        ['$match' => [
            'return_date' => null,
            'due_date' => [
                '$gte' => $today->format('Y-m-d'),
                '$lte' => $twoDaysFromNow
            ]
        ]],
        ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
        // This $lookup now checks for both ISBN and Accession Number
        ['$lookup' => [
            'from' => 'AddBook',
            'let' => [
                'book_isbn' => '$isbn', // From the borrow_book record
                'book_acc' => '$accession_number' // From the borrow_book record
            ],
            'pipeline' => [
                ['$match' => [
                    '$expr' => ['$or' => [
                        // Match by ISBN (if it exists)
                        ['$and' => [['$ne' => ['$$book_isbn', null]], ['$eq' => ['$isbn', '$$book_isbn']]]],
                        // Match by Accession Number (if it exists)
                        ['$and' => [['$ne' => ['$$book_acc', null]], ['$eq' => ['$accession_number', '$$book_acc']]]]
                    ]]
                ]],
                ['$limit' => 1]
            ],
            'as' => 'book_details'
        ]],
        ['$unwind' => '$student_details'],
        ['$unwind' => '$book_details'], // This ensures we only get records with a matched book
        ['$sort' => ['due_date' => 1]]
    ];

    $proactive_cursor = $borrowCollection->aggregate($proactive_pipeline);
    foreach ($proactive_cursor as $doc) {
        $due_date = new DateTime($doc['due_date']);
        $interval = $today->diff($due_date);
        $doc['days_left'] = $interval->days;
        $doc['due_date_formatted'] = $due_date->format('M j, Y'); 
        $proactive_list[] = $doc;
    }


    // --- ★★★ FIX 2: Corrected Overdue Pipeline ★★★ ---
    $overdue_pipeline = [
        ['$match' => [
            'return_date' => null,
            'due_date' => ['$lt' => $today->format('Y-m-d')] // Due date is before today
        ]],
        ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
        // This $lookup now checks for both ISBN and Accession Number
        ['$lookup' => [
            'from' => 'AddBook',
            'let' => [
                'book_isbn' => '$isbn',
                'book_acc' => '$accession_number'
            ],
            'pipeline' => [
                ['$match' => [
                    '$expr' => ['$or' => [
                        ['$and' => [['$ne' => ['$$book_isbn', null]], ['$eq' => ['$isbn', '$$book_isbn']]]],
                        ['$and' => [['$ne' => ['$$book_acc', null]], ['$eq' => ['$accession_number', '$$book_acc']]]]
                    ]]
                ]],
                ['$limit' => 1]
            ],
            'as' => 'book_details'
        ]],
        ['$unwind' => '$student_details'],
        ['$unwind' => '$book_details'], // This ensures we only get records with a matched book
        ['$sort' => ['due_date' => 1]]
    ];

    $overdue_cursor = $borrowCollection->aggregate($overdue_pipeline);
    foreach ($overdue_cursor as $doc) {
        $due_date = new DateTime($doc['due_date']);
        $doc['days_overdue'] = $today->diff($due_date)->days;
        $doc['due_date_formatted'] = $due_date->format('M j, Y'); 
        $overdue_list[] = $doc;
    }

    // --- ★★★ FIX 3: Corrected Returned Pipeline ★★★ ---
    $sevenDaysAgo = (clone $today)->modify('-7 days')->format('Y-m-d H:i:s'); 
    $returned_pipeline = [
        ['$match' => [
            'return_date' => ['$gte' => $sevenDaysAgo] // Returned within the last week
        ]],
        ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'student_details']],
        // This $lookup now checks for both ISBN and Accession Number
        ['$lookup' => [
            'from' => 'AddBook',
            'let' => [
                'book_isbn' => '$isbn',
                'book_acc' => '$accession_number'
            ],
            'pipeline' => [
                ['$match' => [
                    '$expr' => ['$or' => [
                        ['$and' => [['$ne' => ['$$book_isbn', null]], ['$eq' => ['$isbn', '$$book_isbn']]]],
                        ['$and' => [['$ne' => ['$$book_acc', null]], ['$eq' => ['$accession_number', '$$book_acc']]]]
                    ]]
                ]],
                ['$limit' => 1]
            ],
            'as' => 'book_details'
        ]],
        ['$unwind' => '$student_details'],
        ['$unwind' => '$book_details'], // This ensures we only get records with a matched book
        ['$sort' => ['return_date' => -1]]
    ];

    $returned_cursor = $borrowCollection->aggregate($returned_pipeline);
    foreach ($returned_cursor as $doc) {
        $due_date = new DateTime($doc['due_date']);
        $return_date = new DateTime($doc['return_date']);
        
        $doc['status_text'] = 'Returned';
        $doc['status_color'] = 'text-green-600';

        if ($return_date > $due_date) {
            $interval = $due_date->diff($return_date);
            $doc['days_late'] = $interval->days;
            $doc['status_text'] = 'Returned LATE';
            $doc['status_color'] = 'text-yellow-600';
            // Use the 'penalty' field that was saved in the database
            $doc['penalty_text'] = 'Paid: ₱' . number_format($doc['penalty'] ?? 0, 2);
        } else {
            $doc['penalty_text'] = 'No Penalty';
        }
        $returned_list[] = $doc;
    }


} catch (Exception $e) {
    $db_error = "Database Error: " . $e->getMessage();
}

// This logic is from your notification_count.php and is correct
$total_notifications_count = count($proactive_list) + count($overdue_list);

require_once __DIR__ . '/templates/header.php';
?>

<style>
/* Base button style */
.notification-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    border-radius: 0.5rem;
    font-weight: 600;
    transition: all 0.2s;
    cursor: pointer;
    border: 1px solid transparent;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
}
.btn-proactive {
    background-color: #0ea5e9; /* Sky-500 */
    color: white;
}
.btn-proactive:hover {
    background-color: #0284c7; /* Sky-600 */
}
.btn-overdue {
    background-color: #ef4444; /* Red-500 */
    color: white;
}
.btn-overdue:hover {
    background-color: #dc2626; /* Red-600 */
}
.btn-delete {
    background-color: #9ca3af; /* Gray-400 */
    color: white;
}
.btn-delete:hover {
    background-color: #6b7280; /* Gray-500 */
}
.notification-item-row {
    align-items: flex-start;
}
@media (min-width: 768px) {
    .notification-item-row {
        align-items: flex-end; 
    }
}
.due-soon-badge {
    background-color: #FFF7ED;
    border: 1px solid #FDBA74;
    color: #F97316;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: bold;
    display: inline-block;
    margin-right: 5px;
}

/* --- ★★★ FIX 4: Glowing Badge CSS ★★★ --- */
/* This targets the notification badge on your dashboard/header */
.notification-badge-glow {
    background-color: #ef4444; /* bg-red-500 */
    color: white;
    border-radius: 9999px;
    /* Add a pulsing glow */
    box-shadow: 0 0 0 0 rgba(239, 68, 68, 1);
    animation: pulse-red 2s infinite;
}

@keyframes pulse-red {
    0% {
        transform: scale(0.95);
        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
    }
    70% {
        transform: scale(1);
        box-shadow: 0 0 0 10px rgba(239, 68, 68, 0);
    }
    100% {
        transform: scale(0.95);
        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
    }
}
/* --- END OF FIX 4 --- */

</style>



<main id="main-content" class="flex-1 p-6 md:p-10">
    <header class="mb-8">
        <h1 class="text-4xl font-bold tracking-tight">Notifications</h1>
        <p class="text-secondary mt-2">Manage proactive and overdue book alerts.</p>
    </header>

    <div id="status-message"></div>

    <h2 class="text-2xl font-bold mb-4 flex items-center gap-2">
        <i data-lucide="bell" class="w-6 h-6 text-orange-500"></i> Due Soon (<span class="text-orange-500"><?php echo count($proactive_list); ?></span>)
    </h2>
    
    <div class="mb-8 space-y-4" id="proactive-list">
        <?php if (empty($proactive_list)): ?>
            <div class="bg-card p-8 rounded-2xl border border-theme shadow-sm text-center text-secondary">
                   <i data-lucide="check-circle-2" class="w-10 h-10 mx-auto text-green-500"></i>
                   <h3 class="mt-2 text-xl font-semibold">All Cleared!</h3>
                 <p class="mt-2">No books are due within the next 2 days.</p>
            </div>
        <?php else: ?>
            <?php foreach ($proactive_list as $item): ?>
                <?php $bookDetails = $item['book_details'] ?? null; // Ensure book details exist ?>
                <div class="bg-card p-4 rounded-2xl border border-theme shadow-sm flex flex-col md:flex-row notification-item-row gap-4" data-borrow-id="<?php echo htmlspecialchars((string)$item['_id']); ?>">
                    <div class="flex-grow flex items-start gap-4">
                        <img src="<?php echo htmlspecialchars($bookDetails['thumbnail'] ?? 'https://placehold.co/80x120/f1f5f9/475569?text=N/A'); ?>" alt="Book Cover" class="w-12 h-16 object-cover rounded-md flex-shrink-0">
                        <div>
                            <p class="font-bold text-primary"><?php echo htmlspecialchars($bookDetails['title'] ?? $item['title'] ?? 'Unknown Title'); ?></p>
                            <p class="text-sm text-secondary">Borrowed by: <span class="font-medium"><?php echo htmlspecialchars($item['student_details']['first_name'] . ' ' . $item['student_details']['last_name']); ?></span></p>
                            
                            <p class="text-sm font-bold text-green-600 mt-1">
                                Due Date: <?php echo $item['due_date_formatted'] ?? (new DateTime($item['due_date']))->format('M j, Y'); ?> 
                            </p>
                            <p class="text-xs text-orange-500 font-semibold">
                                <?php echo $item['days_left'] == 0 ? 'DUE TODAY' : 'Due in ' . $item['days_left'] . ' day(s)'; ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2 w-full md:w-auto">
                        <button class="send-reminder-btn notification-btn btn-proactive" 
                                data-action="send_proactive_reminder"
                                data-borrow-id="<?php echo htmlspecialchars((string)$item['_id']); ?>" 
                                data-student-email="<?php echo htmlspecialchars($item['student_details']['email'] ?? ''); ?>">
                            <i data-lucide="mail" class="w-4 h-4"></i>
                            <span>Send Reminder</span>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>


    <h2 class="text-2xl font-bold mb-4 mt-10 flex items-center gap-2">
    <i data-lucide="siren" class="w-6 h-6 text-red-600"></i> Overdue Books (<span class="text-red-500"><?php echo count($overdue_list); ?></span>)
</h2>
<div class="space-y-4" id="overdue-list"> 
    <?php if (isset($db_error)): ?>
        <div class="bg-red-100 text-red-700 p-4 rounded-lg"><?php echo $db_error; ?></div>
    <?php elseif (empty($overdue_list)): ?>
        <div class="bg-card p-4 rounded-2xl border border-theme shadow-sm text-center text-secondary">
            <i data-lucide="check-circle-2" class="w-10 h-10 mx-auto text-green-500"></i>
            <h3 class="mt-2 text-xl font-semibold">All Cleared!</h3>
            <p class="mt-1">No books are currently overdue.</p>
        </div>
        <?php else: ?>
        <?php foreach ($overdue_list as $item): ?>
            <?php $bookDetails = $item['book_details'] ?? null; // Ensure book details exist ?>
            <div class="bg-card p-4 rounded-2xl border border-theme shadow-sm flex flex-col md:flex-row notification-item-row gap-4" data-borrow-id="<?php echo htmlspecialchars((string)$item['_id']); ?>">
                <div class="flex-grow flex items-start gap-4">
                    <img src="<?php echo htmlspecialchars($bookDetails['thumbnail'] ?? 'https://placehold.co/80x120/f1f5f9/475569?text=N/A'); ?>" alt="Book Cover" class="w-12 h-16 object-cover rounded-md flex-shrink-0">
                    <div>
                        <p class="font-bold text-primary"><?php echo htmlspecialchars($bookDetails['title'] ?? $item['title'] ?? 'Unknown Title'); ?></p>
                        <p class="text-sm text-secondary">Borrowed by: <span class="font-medium"><?php echo htmlspecialchars($item['student_details']['first_name'] . ' ' . $item['student_details']['last_name']); ?></span></p>
                        
                        <p class="text-sm font-bold text-red-500 mt-1">
                            Due Date: <span class="text-red-500"><?php echo $item['due_date_formatted'] ?? (new DateTime($item['due_date']))->format('M j, Y'); ?></span>
                        </p>
                        <p class="text-xs font-bold text-red-500">
                            Overdue by <?php echo $item['days_overdue']; ?> day(s)
                        </p>
                    </div>
                </div>
                <div class="flex flex-col gap-2 w-full md:w-auto">
                    <button class="send-reminder-btn notification-btn btn-overdue" 
                            data-action="send_overdue_reminder"
                            data-borrow-id="<?php echo htmlspecialchars((string)$item['_id']); ?>" 
                            data-student-email="<?php echo htmlspecialchars($item['student_details']['email'] ?? ''); ?>">
                        <i data-lucide="siren" class="w-4 h-4"></i>
                        <span>Send Overdue</span>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>


    <h2 class="text-2xl font-bold mb-4 mt-10 flex items-center gap-2">
        <i data-lucide="check-circle" class="w-6 h-6 text-green-600"></i> Recently Returned
    </h2>
    <div class="space-y-4" id="returned-list"> 
        <?php if (empty($returned_list)): ?>
            <div class="bg-card p-8 rounded-2xl border border-theme shadow-sm text-center text-secondary">
                <p>No books returned in the last 7 days.</p>
            </div>
        <?php else: ?>
            <?php foreach ($returned_list as $item): ?>
                <?php $bookDetails = $item['book_details'] ?? null; // Ensure book details exist ?>
                <div class="bg-card p-4 rounded-2xl border border-theme shadow-sm flex flex-col md:flex-row notification-item-row gap-4" data-borrow-id="<?php echo htmlspecialchars((string)$item['_id']); ?>">
                    <div class="flex-grow flex items-start gap-4">
                        <img src="<?php echo htmlspecialchars($bookDetails['thumbnail'] ?? 'https://placehold.co/80x120/f1f5f9/475569?text=N/A'); ?>" alt="Book Cover" class="w-12 h-16 object-cover rounded-md flex-shrink-0">
                        <div>
                            <p class="font-bold text-primary"><?php echo htmlspecialchars($bookDetails['title'] ?? $item['title'] ?? 'Unknown Title'); ?></p>
                            <p class="text-sm text-secondary">Borrowed by: <span class="font-medium"><?php echo htmlspecialchars($item['student_details']['first_name'] . ' ' . $item['student_details']['last_name']); ?></span></p>
                            
                            <p class="text-sm font-bold <?php echo $item['status_color']; ?> mt-1">
                                Status: <?php echo $item['status_text']; ?>
                            </p>
                            <p class="text-xs text-secondary">
                                <?php echo $item['penalty_text']; ?>
                            </p>
                        </div>
                    </div>
                    <div class="w-full md:w-auto flex flex-col gap-2">
                        <span class="text-sm text-secondary italic">Returned: <?php echo (new DateTime($item['return_date']))->format('M j, Y'); ?></span>
                        <button class="delete-notification-btn notification-btn btn-delete" 
                                data-borrow-id="<?php echo htmlspecialchars((string)$item['_id']); ?>">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                            <span>Clear Record</span>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<script>
// Function to update the badge count (needs to be implemented in your sidebar logic too)
function updateBadgeCount(delta) {
    const proactiveList = document.getElementById('proactive-list');
    const overdueList = document.getElementById('overdue-list');

    if (delta !== 0) {
        // Find the notification badge on the page (it might have the glow class)
        const badge = document.querySelector('.notification-badge-glow') || document.querySelector('.notification-badge'); // Find by glow or regular class
        if (badge) {
            let currentCount = parseInt(badge.textContent) || 0;
            let newCount = Math.max(0, currentCount + delta);
            badge.textContent = newCount;
            
            // If count drops to 0, remove the glow class
            if (newCount === 0) {
                badge.classList.remove('notification-badge-glow');
            }
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (!window.showStatus) {
        window.showStatus = (message, isError = false) => {
            const statusDiv = document.getElementById('status-message');
            const colorClass = isError ? 'bg-red-100 border-red-500 text-red-800' : 'bg-green-100 border-green-500 text-green-800';
            statusDiv.innerHTML = `<div class="${colorClass} border-l-4 p-4 rounded-r-lg mb-6" role="alert"><p>${message}</p></div>`;
            window.scrollTo({ top: 0, behavior: 'smooth' });
            setTimeout(() => statusDiv.innerHTML = '', 5000);
        };
    }
    
    if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
        lucide.createIcons();
    }

    // --- REMINDER BUTTON LOGIC (Existing) ---
    document.querySelectorAll('.send-reminder-btn').forEach(button => {
        button.addEventListener('click', async () => {
            const borrowId = button.dataset.borrowId;
            const studentEmail = button.dataset.studentEmail;
            const actionType = button.dataset.action; 

            if (!studentEmail) {
                window.showStatus('This student does not have an email on file. Cannot send reminder.', true);
                return;
            }

            const originalContent = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i data-lucide="loader-2" class="animate-spin w-4 h-4"></i><span>Sending...</span>';
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }

            const formData = new FormData();
            formData.append('action', actionType); 
            formData.append('borrow_id', borrowId);

            try {
                const response = await fetch('borrow_action.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    window.showStatus(result.message, false);
                    button.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i><span>Sent!</span>';
                } else {
                    throw new Error(result.message || 'Unknown error');
                }
            } catch (error) {
                window.showStatus(`Failed to send reminder: ${error.message}`, true);
                button.disabled = false;
                button.innerHTML = originalContent;
                if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                    lucide.createIcons();
                }
            }
        });
    });

    // --- DELETE BUTTON LOGIC (Existing) ---
    document.querySelectorAll('.delete-notification-btn').forEach(button => {
        button.addEventListener('click', async (event) => {
            const borrowId = button.dataset.borrowId;
            const notificationItem = button.closest('[data-borrow-id]');
            
            if (!confirm("Are you sure you want to permanently delete this returned transaction record?")) {
                return;
            }

            const originalContent = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i data-lucide="loader-2" class="animate-spin w-4 h-4"></i><span>Deleting...</span>';
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }

            const formData = new FormData();
            formData.append('action', 'delete_returned_record'); 
            formData.append('_id', borrowId);

            try {
                const response = await fetch('borrow_action.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    window.showStatus(result.message, false);
                    
                    if (notificationItem) {
                        notificationItem.style.transition = 'opacity 0.5s, transform 0.5s';
                        notificationItem.style.opacity = '0';
                        notificationItem.style.transform = 'translateX(100%)';
                        
                        setTimeout(() => {
                            notificationItem.remove();
                        }, 500); 
                    }
                } else {
                    throw new Error(result.message || 'Unknown error');
                }
            } catch (error) {
                window.showStatus(`Failed to delete record: ${error.message}`, true);
                button.disabled = false;
                button.innerHTML = originalContent;
                if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                    lucide.createIcons();
                }
            }
        });
    });

    // --- ★★★ FIX 5: Apply Glow Class to Badge ★★★ ---
    // Find the badge in the header/sidebar (this is a guess based on your dashboard.php)
    // This script will run on notification.php, but it will style the badge in your sidebar.
    const notificationBadge = document.querySelector('a[href="notification.php"] .notification-badge');
    if (notificationBadge && parseInt(notificationBadge.textContent) > 0) {
        notificationBadge.classList.add('notification-badge-glow');
    }
});
</script>

<?php
require_once __DIR__ . '/templates/footer.php';
?>