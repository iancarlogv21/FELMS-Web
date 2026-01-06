<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$currentPage = 'dashboard';
$pageTitle = 'Dashboard - FELMS';

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

if (!function_exists('createAcronym')) {
    /**
     * Attempts to create an acronym from a string, ignoring common prepositions/articles and parenthetical content.
     */
    function createAcronym(string $name): string {
        // 1. Remove all content within parentheses (including the parentheses themselves)
        // This addresses issues like "Program (ACRONYM)" -> "ACRONYM"
        $nameWithoutParentheses = preg_replace('/\s*\(.*?\)\s*/', '', $name);
        
        // Use the cleaned name for acronym generation. If the name was entirely in parentheses, use the original.
        $nameForAcronym = trim($nameWithoutParentheses ?: $name);

        // List of common words to exclude from the acronym (e.g., 'of', 'in', 'and')
        $excludeWords = ['of', 'in', 'and', 'the', 'for', 'a']; 
        
        // Split the name by spaces, commas, hyphens, etc.
        $parts = preg_split('/[\s,-]+/', $nameForAcronym);
        $acronym = '';
        
        foreach ($parts as $part) {
            $trimmedPart = trim($part);
            if (empty($trimmedPart)) {
                continue;
            }
            
            // Check if the lowercase word is in the exclusion list
            if (in_array(strtolower($trimmedPart), $excludeWords)) {
                continue;
            }
            
            // If the word is a major component (not excluded), take its first letter
            $acronym .= strtoupper(substr($trimmedPart, 0, 1));
        }
        
        // Return the generated acronym if it's substantial (more than one letter)
        if (!empty($acronym) && strlen($acronym) > 1) {
            return $acronym;
        }

        // Final fallback to the original trimmed name if acronym generation failed or produced a single letter.
        return trim($name);
    }
}


$db_error = null;
$username = htmlspecialchars($_SESSION["username"]);
$totalBooks = 0;
$totalStudents = 0;
$activeBorrows = 0;
$overdueBooks = 0;
$totalPenalties = 0;
$topChoicesBooks = [];
$topBorrowersList = []; 
$booksList = [];
$genreLabels = [];
$genreData = [];
$bookStatusLabels = ["Available", "Borrowed", "Overdue"];
$bookStatusData = [];
$monthlyLabels = [];
$monthlyData = [];
$overdueByProgramLabels = [];
$overdueByProgramData = [];

try {
    $dbInstance = Database::getInstance();
    $addBookCollection = $dbInstance->books();
    $studentsCollection = $dbInstance->students();
    $borrowCollection = $dbInstance->borrows();

    // --- STATS CARDS DATA ---
    $totalBooks = $addBookCollection->countDocuments();
    $totalStudents = $studentsCollection->countDocuments();
    $activeBorrows = $borrowCollection->countDocuments(['return_date' => null]);
    $currentDate = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $overdueBooks = $borrowCollection->countDocuments([
        'due_date' => ['$lt' => $currentDate->format('Y-m-d H:i:s')],
        'return_date' => null
    ]);


$totalPenalties = 0;
$penaltyRate = 10; 

// First, get the sum of penalties for books that have already been returned and had a penalty saved.
$returnedPenaltyPipeline = [
    ['$match' => ['return_date' => ['$ne' => null], 'penalty' => ['$gt' => 0]]],
    ['$group' => ['_id' => null, 'total' => ['$sum' => '$penalty']]]
];
$returnedResult = $borrowCollection->aggregate($returnedPenaltyPipeline)->toArray();
$totalPenalties += $returnedResult[0]['total'] ?? 0;

// Next, find all currently overdue books.
$overdueCursor = $borrowCollection->find([
    'due_date' => ['$lt' => $currentDate->format('Y-m-d')],
    'return_date' => null
]);

// Loop through them in PHP and calculate the penalty for each.
foreach ($overdueCursor as $borrow) {
    try {
        $dueDate = new DateTime($borrow['due_date']);
        // Calculate days overdue. We use `+1` day to include the due date in penalty calculation.
        $daysOverdue = $currentDate->diff($dueDate)->days;
        if ($currentDate > $dueDate) {
            $totalPenalties += $daysOverdue * $penaltyRate;
        }
    } catch (Exception $e) {
        // Ignore if date is invalid
    }
}

   $genrePipeline = [
    
    ['$lookup' => ['from' => 'AddBook', 'localField' => 'isbn', 'foreignField' => 'isbn', 'as' => 'bookDetails']],
    ['$unwind' => '$bookDetails'],
    ['$group' => ['_id' => '$bookDetails.genre', 'count' => ['$sum' => 1]]],
    ['$sort' => ['count' => -1]],
    ['$limit' => 5]
];
$genreCursor = $borrowCollection->aggregate($genrePipeline);
foreach ($genreCursor as $genre) {
    $genreLabels[] = $genre['_id'] ?? 'Unknown';
    $genreData[] = $genre['count'];
}

    // --- BOOK STATUS OVERVIEW (PIE CHART) ---
    $availableBooks = $totalBooks - $activeBorrows;
    $onTimeBorrows = $activeBorrows - $overdueBooks;
    $bookStatusData = [$availableBooks, $onTimeBorrows, $overdueBooks];

   
    // --- MONTHLY TRENDS DATA FETCHING ---
// --- MONTHLY TRENDS DATA FETCHING ---

// Initialize arrays to store results
$monthlyBorrowCounts = [];
$monthlyReturnCounts = []; 

// Define the start date (12 months ago) and current date for the entire period
$startDate = new DateTime('now', new DateTimeZone('Asia/Manila'));
$startDate->modify('-11 months')->modify('first day of this month');

$currentDateString = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
$startDateString = $startDate->format('Y-m-d H:i:s');


// 1. Fetch Monthly Borrow Counts (Current Logic Refined)
$borrowPipeline = [
    // Use the calculated start date for matching
    ['$match' => ['borrow_date' => ['$gte' => $startDateString]]],
    ['$project' => ['month' => ['$substr' => ['$borrow_date', 0, 7]]]],
    ['$group' => ['_id' => '$month', 'count' => ['$sum' => 1]]],
    ['$sort' => ['_id' => 1]]
];
$borrowResults = iterator_to_array($borrowCollection->aggregate($borrowPipeline));
foreach ($borrowResults as $result) {
    $monthlyBorrowCounts[$result['_id']] = $result['count'];
}


// 2. Fetch Monthly Return Counts (Current Logic Refined)
$returnPipeline = [
    // Match records returned since the start date
    ['$match' => ['return_date' => ['$ne' => null], 'return_date' => ['$gte' => $startDateString]]],
    ['$project' => ['month' => ['$substr' => ['$return_date', 0, 7]]]],
    ['$group' => ['_id' => '$month', 'count' => ['$sum' => 1]]],
    ['$sort' => ['_id' => 1]]
];
$returnResults = iterator_to_array($borrowCollection->aggregate($returnPipeline));
foreach ($returnResults as $result) {
    $monthlyReturnCounts[$result['_id']] = $result['count'];
}


// 3. Populate Arrays for 12 months (CRITICAL FIX: Iterate Forward)
$monthlyLabels = [];
$monthlyBorrowData = []; 
$monthlyReturnData = [];

for ($i = 0; $i < 12; $i++) {
    // Clone the start date and advance it month-by-month
    $date = clone $startDate;
    $date->modify("+$i months");

    $monthKey = $date->format('Y-m');
    
    // Add label in chronological order
    $monthlyLabels[] = $date->format('M Y');
    
    // Add data points in chronological order (zero-filling missing months)
    $monthlyBorrowData[] = $monthlyBorrowCounts[$monthKey] ?? 0;
    $monthlyReturnData[] = $monthlyReturnCounts[$monthKey] ?? 0;
}


// Pass new structured data to JavaScript
$monthlyData = [
    'borrow' => $monthlyBorrowData,
    'return' => $monthlyReturnData
];

    // --- OVERDUE BOOKS BY PROGRAM (BAR CHART) ---
    $overdueProgramPipeline = [
        ['$match' => ['due_date' => ['$lt' => $currentDate->format('Y-m-d H:i:s')], 'return_date' => null]],
        ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'studentDetails']],
        ['$unwind' => '$studentDetails'],
        ['$group' => ['_id' => '$studentDetails.program', 'count' => ['$sum' => 1]]],
        ['$sort' => ['count' => -1]]
    ];
    $overdueProgramCursor = $borrowCollection->aggregate($overdueProgramPipeline);
    foreach ($overdueProgramCursor as $program) {
        $programName = $program['_id'] ?? 'N/A';
        // <<<< THIS IS THE MISSING CALL >>>>
        $acronym = createAcronym($programName); 

        $overdueByProgramLabels[] = $acronym; // Now uses the short acronym
        $overdueByProgramData[] = $program['count'];
    }

    // --- TOP CHOICES (MOST BORROWED BOOKS) ---
    $topChoicesPipeline = [
        ['$group' => ['_id' => '$isbn', 'borrow_count' => ['$sum' => 1]]],
        ['$sort' => ['borrow_count' => -1]],
        ['$limit' => 8],
        ['$lookup' => ['from' => 'AddBook', 'localField' => '_id', 'foreignField' => 'isbn', 'as' => 'bookDetails']],
        ['$unwind' => '$bookDetails'],
        ['$project' => [
        // Include the actual book ID needed for the detail API call
        'book_id' => '$bookDetails._id', 
        'title' => '$bookDetails.title', 
        'thumbnail' => '$bookDetails.thumbnail'
    ]]
    ];
    $topChoicesBooks = iterator_to_array($borrowCollection->aggregate($topChoicesPipeline));
    
    // --- NEW: TOP ACTIVE BORROWERS ---
    $topBorrowersPipeline = [
        ['$group' => [
            '_id' => '$student_no',
            'borrow_count' => ['$sum' => 1]
        ]],
        ['$sort' => ['borrow_count' => -1]],
        ['$limit' => 4],
        ['$lookup' => [
            'from' => 'Students',
            'localField' => '_id',
            'foreignField' => 'student_no',
            'as' => 'studentDetails'
        ]],
        ['$unwind' => '$studentDetails'],
        ['$project' => [
        'student_id' => '$studentDetails._id', 
        'student_no' => '$_id',
        'borrow_count' => 1,
        'first_name' => '$studentDetails.first_name',
        'last_name' => '$studentDetails.last_name',
        'image' => '$studentDetails.image',
        'gender' => '$studentDetails.gender'
    ]]
    ];
    $topBorrowersList = iterator_to_array($borrowCollection->aggregate($topBorrowersPipeline));

    // --- FETCH RECENT BOOKS ---
    $booksList = iterator_to_array($addBookCollection->find([], ['limit' => 4, 'sort' => ['_id' => -1]]));

} catch (Exception $e) {
    $db_error = "MongoDB Connection Error: " . $e->getMessage();
}


// --- 1. SET THE GLOBAL COUNT FIRST (Must run before templates) ---
require_once __DIR__ . '/notification_count.php';

// 2. Assign the calculated total to the local variable for the badge (which is now 10)
$profile_badge_count = $total_notifications_count ?? 0;

require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/sidebar.php';

?>

<style>
 /* Custom CSS for the Collapse/Expand Toggle */
/* THIS IS CRUCIAL FOR SMOOTH ANIMATION */
#synopsis-text {
    transition: max-height 0.5s ease-in-out; 
}

/* This is needed for the initial collapsed state */
#synopsis-text.collapsed {
    /* max-height is set dynamically in JS, but overflow is needed */
    overflow: hidden;
}

/* 🏆 The FIX for Icon Rotation */
#synopsis-icon-wrapper svg {
    transition: transform 0.3s ease-in-out; 
    transform: rotate(0deg); /* Default state: Arrow down */
}

.synopsis-rotated svg {
    transform: rotate(-180deg) !important; /* Rotates to arrow UP */
}


</style>
<main id="main-content" class="flex-1 p-6 md:p-10 overflow-y-auto transition-all duration-300 ease-in-out">
    <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
    
        <div>
            <h1 class="text-3xl lg:text-4xl font-bold">Hello, <?php echo htmlspecialchars($username); ?>!</h1>
            <p class="text-secondary mt-1"><?php echo date('l, F j, Y'); ?></p>
        </div>

        <div class="flex items-center gap-4 mt-4 md:mt-0"> 
            <form action="search.php" method="GET" class="relative flex-shrink-0 w-full md:w-64">
                <input type="search" name="q" placeholder="Search..." class="w-full pl-10 pr-4 py-2.5 border rounded-full bg-card border-theme focus:outline-none focus:ring-2 focus:ring-[var(--accent-color)] shadow-sm" required>
                <i data-lucide="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-5 h-5 text-secondary"></i>
            </form>

            <?php 
            // CRITICAL: This variable must be set to the combined total (10)
            $profile_badge_count = $total_notifications_count ?? 0;
            ?>

            <div class="relative flex-shrink-0">
                
                <button id="profile-dropdown-button" class="w-12 h-12 rounded-full bg-card flex items-center justify-center ring-2 ring-offset-2 ring-card-border focus:outline-none focus:ring-[var(--accent-color)] transition-shadow">
                    <?php if (isset($_SESSION["profile_image"]) && !empty($_SESSION["profile_image"])): ?>
                        <img src="<?php echo htmlspecialchars($_SESSION["profile_image"]); ?>" alt="Profile" class="w-full h-full rounded-full object-cover">
                    <?php else: ?>
                        <span class="font-bold text-primary text-lg"><?php echo strtoupper(substr($username, 0, 1)); ?></span>
                    <?php endif; ?>
                </button>

                <?php if ($profile_badge_count > 0): ?>
                <a href="notification.php" class="absolute top-0 right-0 transform translate-x-1/4 -translate-y-1/4 
                              w-6 h-6 bg-red-600 border-2 border-white dark:border-slate-800 rounded-full 
                              flex items-center justify-center text-xs font-bold text-white shadow-md" aria-label="View notifications">
                    <?php echo $profile_badge_count; ?>
                </a>
                <?php endif; ?>

                <div id="profile-dropdown-menu" class="absolute right-0 mt-2 w-72 bg-card border border-theme rounded-xl shadow-lg z-50 overflow-hidden hidden transition-all duration-300 ease-in-out transform opacity-0 -translate-y-2">
                    <div class="p-2">
                        <div class="flex items-center gap-3 p-3 rounded-lg">
                            <div class="w-10 h-10 rounded-full bg-card flex items-center justify-center flex-shrink-0">
                                 <?php if (isset($_SESSION["profile_image"]) && !empty($_SESSION["profile_image"])): ?>
                                     <img src="<?php echo htmlspecialchars($_SESSION["profile_image"]); ?>" alt="Profile" class="w-full h-full rounded-full object-cover">
                                 <?php else: ?>
                                     <span class="font-bold text-primary text-md"><?php echo strtoupper(substr($username, 0, 1)); ?></span>
                                 <?php endif; ?>
                            </div>
                            <div>
                                <p class="font-bold text-text text-md truncate"><?php echo htmlspecialchars($username); ?></p>
                                <p class="text-sm text-secondary">Administrator Profile</p>
                            </div>
                        </div>
                        
                        <hr class="my-2 border-theme">

                        <a href="profile.php" class="flex items-center gap-4 p-3 rounded-lg hover:bg-body transition-colors">
                            <i data-lucide="user" class="w-5 h-5 text-secondary"></i>
                            <span class="font-semibold text-text">Personal Information</span>
                        </a>

                        <a href="loginhistory.php" class="flex items-center gap-4 p-3 rounded-lg hover:bg-body transition-colors">
                            <i data-lucide="history" class="w-5 h-5 text-secondary"></i>
                            <span class="font-semibold text-text">Login History</span>
                        </a>
                        
                        <a href="notification.php" class="flex items-center gap-4 p-3 rounded-lg hover:bg-body transition-colors">
                            <i data-lucide="bell" class="w-5 h-5 text-secondary"></i>
                            <span class="font-semibold text-text">Notifications</span>
                            <?php if ($profile_badge_count > 0): ?>
                                <span class="ml-auto bg-red-600 text-white text-xs font-bold w-5 h-5 rounded-full flex items-center justify-center">
                                    <?php echo $profile_badge_count; ?>
                                </span>
                            <?php endif; ?>
                        </a>

                       <hr class="my-2 border-theme">
<a href="logout.php" class="flex items-center gap-4 p-3 rounded-lg 
    hover:bg-red-100 transition-colors">
    <i data-lucide="log-out" class="w-5 h-5 text-[var(--accent-color)]"></i>
    <span class="font-semibold text-[var(--accent-color)]">Log Out</span>
</a>
                    </div>
                </div>
            </div>
            </div>
        </header>
    
    <?php if ($db_error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg shadow-sm" role="alert">
        <p class="font-bold">Database Error</p>
        <p><?php echo htmlspecialchars($db_error); ?></p>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-6 mb-8">
    
    <a href="books.php" class="block transform hover:-translate-y-1 transition-transform duration-300">
        <div class="bg-card h-full p-6 rounded-xl border border-theme flex items-center gap-4 shadow-sm">
            <div class="bg-red-100 p-3 rounded-full"><i data-lucide="book" class="w-7 h-7 text-red-600"></i></div>
            <div>
                <p class="text-secondary">Total Books</p>
                <p class="text-3xl font-bold"><?php echo number_format($totalBooks); ?></p>
            </div>
        </div>
    </a>

    <a href="student.php" class="block transform hover:-translate-y-1 transition-transform duration-300">
        <div class="bg-card h-full p-6 rounded-xl border border-theme flex items-center gap-4 shadow-sm">
            <div class="bg-blue-100 p-3 rounded-full"><i data-lucide="users" class="w-7 h-7 text-blue-600"></i></div>
            <div>
                <p class="text-secondary">Total Students</p>
                <p class="text-3xl font-bold"><?php echo number_format($totalStudents); ?></p>
            </div>
        </div>
    </a>

    <a href="active_borrowings.php" class="block transform hover:-translate-y-1 transition-transform duration-300">
        <div class="bg-card h-full p-6 rounded-xl border border-theme flex items-center gap-4 shadow-sm">
            <div class="bg-green-100 p-3 rounded-full"><i data-lucide="arrow-up-right" class="w-7 h-7 text-green-600"></i></div>
            <div>
                <p class="text-secondary">Issued Books</p>
                <p class="text-3xl font-bold"><?php echo number_format($activeBorrows); ?></p>
            </div>
        </div>
    </a>

    <a href="overdue_books.php" class="block transform hover:-translate-y-1 transition-transform duration-300">
        <div class="bg-card h-full p-6 rounded-xl border border-theme flex items-center gap-4 shadow-sm">
            <div class="bg-yellow-100 p-3 rounded-full"><i data-lucide="alert-triangle" class="w-7 h-7 text-yellow-600"></i></div>
            <div>
                <p class="text-secondary">Overdue Books</p>
                <p class="text-3xl font-bold"><?php echo number_format($overdueBooks); ?></p>
            </div>
        </div>
    </a>

    <a href="penalty_report.php" class="block transform hover:-translate-y-1 transition-transform duration-300">
        <div class="bg-card h-full p-6 rounded-xl border border-theme flex items-center gap-4 shadow-sm">
            <div class="bg-orange-100 p-3 rounded-full"><i data-lucide="siren" class="w-7 h-7 text-orange-600"></i></div>
            <div>
                <p class="text-secondary">Total Penalties</p>
                <p class="text-3xl font-bold">₱<?php echo number_format($totalPenalties, 2); ?></p>
            </div>
        </div>
    </a>

</div>
    
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-8 mb-8">
        <div class="xl:col-span-2 flex flex-col gap-8">
            <div class="bg-card p-6 rounded-xl border border-theme shadow-sm chart-container">
                <h3 class="text-xl font-semibold mb-4">Monthly Book Circulation</h3>
                <div class="h-80"><canvas id="monthlyBorrowingChart"></canvas></div>
            </div>
            <div class="mb-8">
                <h3 class="text-xl font-semibold mb-4">Top Choices</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-6">
    <?php if (!empty($topChoicesBooks)): ?>
        <?php foreach ($topChoicesBooks as $index => $book): ?>
        <button type="button" class="group text-center view-details-btn" data-type="book" data-id="<?php 
            // Use the new 'book_id' field, casting the MongoDB ObjectId to string
            echo htmlspecialchars((string)($book['book_id'] ?? '')); 
        ?>">
            <div class="relative aspect-[2/3] w-full bg-card p-2 rounded-lg overflow-hidden shadow-md transform group-hover:scale-105 transition-transform duration-300 border border-theme cursor-pointer">
                <div class="absolute top-0 left-0 bg-red-600 text-white w-8 h-8 flex items-center justify-center font-bold text-lg rounded-br-lg z-10"><?= $index + 1 ?></div>
                <img src="<?php echo htmlspecialchars($book['thumbnail'] ?? 'https://placehold.co/200x300/E2E8F0/4A5568?text=Book'); ?>" alt="<?php echo htmlspecialchars($book['title'] ?? 'Untitled'); ?>" class="w-full h-full object-cover rounded-md">
            </div>
            <p class="font-semibold text-secondary text-sm truncate mt-2 px-1"><?php echo htmlspecialchars($book['title'] ?? 'N/A'); ?></p>
        </button>
        <?php endforeach; ?>
    <?php else: ?>
                        <p class="col-span-full text-center text-secondary py-8">Not enough borrow data to determine top choices.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-8">
            <div class="bg-card p-6 rounded-xl border border-theme shadow-sm chart-container">
                <h3 class="text-xl font-semibold mb-4">Genre Popularity</h3>
                <div class="h-64"><canvas id="genrePopularityChart"></canvas></div>
            </div>
            <div class="bg-card p-6 rounded-xl border border-theme shadow-sm chart-container">
                <h3 class="text-xl font-semibold mb-4">Book Status Overview</h3>
                <div class="h-64"><canvas id="bookStatusChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
        <div class="bg-card p-6 rounded-xl border border-theme shadow-sm chart-container">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">Overdue Books by Program</h3>
            </div>
            <div class="h-80"><canvas id="overdueByProgramChart"></canvas></div>
        </div>
        
        <div class="bg-card p-6 rounded-xl border border-theme shadow-sm">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">Top Active Borrowers</h3>
                <a href="student.php" class="text-sm font-medium text-[var(--accent-color)] hover:underline">View All Students</a>
            </div>
            <div class="space-y-2">
                <div class="space-y-2">
    <?php if (!empty($topBorrowersList)): ?>
        <?php foreach ($topBorrowersList as $borrower): ?>
        <button type="button" class="view-details-btn w-full relative group flex items-center justify-between p-2 rounded-lg hover:bg-[var(--bg-color)] transition-colors text-left" data-type="student" data-id="<?php echo htmlspecialchars((string)($borrower['student_id'] ?? $borrower['student_no'])); ?>">
            <div class="flex items-center gap-3">
                <img src="<?php echo htmlspecialchars(getStudentPhotoUrl($borrower)); ?>" class="w-10 h-10 rounded-full object-cover">
                <div>
                    <p class="font-semibold"><?php echo htmlspecialchars(($borrower['first_name'] ?? '') . ' ' . ($borrower['last_name'] ?? '')); ?></p>
                    <p class="text-sm text-secondary"><?php echo htmlspecialchars($borrower['student_no'] ?? 'N/A'); ?></p>
                </div>
            </div>
            <p class="text-sm font-medium text-secondary bg-[var(--bg-color)] px-2.5 py-1 rounded-full w-24 text-center">
                <?php echo $borrower['borrow_count']; ?> Books
            </p>
        </button>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="text-center text-secondary py-8">No borrowing data available to show top borrowers.</p>
    <?php endif; ?>
</div>
            </div>
        </div>
        <div class="bg-card p-6 rounded-xl border border-theme shadow-sm">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">Recent Books</h3>
                <a href="books.php" class="text-sm font-medium text-[var(--accent-color)] hover:underline">View All Books</a>
            </div>
            <div class="space-y-4">
                <?php foreach ($booksList as $book): ?>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <img src="<?php echo htmlspecialchars($book['thumbnail'] ?? 'https://placehold.co/40x64/E2E8F0/4A5568?text=B'); ?>" class="w-10 h-16 object-cover rounded-md">
                        <div>
                            <p class="font-semibold truncate w-40"><?php echo htmlspecialchars($book['title'] ?? 'N/A'); ?></p>
                            <p class="text-sm text-secondary"><?php echo htmlspecialchars($book['authors'][0] ?? 'N/A'); ?></p>
                        </div>
                    </div>
                    <span class="text-sm font-medium text-green-800 bg-green-100 px-2.5 py-1 rounded-full"><?php echo htmlspecialchars($book['quantity'] ?? '0'); ?> In Stock</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</main>

<div id="details-modal-backdrop" class="fixed inset-0 bg-black bg-opacity-60 z-40 hidden transition-opacity duration-300 ease-in-out"></div>
<div id="details-modal" class="fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-xl bg-card border border-theme rounded-xl shadow-2xl z-50 hidden transform transition-all duration-300 ease-in-out scale-95 opacity-0">
    <div class="flex items-center justify-between p-4 border-b border-theme">
        <h3 id="modal-title" class="text-xl font-bold">Details</h3>
        <button id="modal-close-btn" class="p-1 rounded-full hover:bg-body">
            <i data-lucide="x" class="w-6 h-6 text-secondary"></i>
        </button>
    </div>
    <div id="modal-content" class="p-6 max-h-[70vh] overflow-y-auto">
        <div class="text-center py-10">
            <i data-lucide="loader-2" class="w-12 h-12 mx-auto text-[var(--accent-color)] animate-spin"></i>
            <p class="mt-4 text-secondary">Loading Details...</p>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- CHART DATA (from PHP) ---
    const chartData = {
       monthly: { 
        labels: <?php echo json_encode($monthlyLabels); ?>, 
        // CRITICAL: Updated data source reference
        borrow: <?php echo json_encode($monthlyData['borrow']); ?>, 
        return: <?php echo json_encode($monthlyData['return']); ?> 
    },
        genre: { labels: <?php echo json_encode($genreLabels); ?>, data: <?php echo json_encode($genreData); ?> },
        bookStatus: { labels: <?php echo json_encode($bookStatusLabels); ?>, data: <?php echo json_encode($bookStatusData); ?> },
        overdue: { labels: <?php echo json_encode($overdueByProgramLabels); ?>, data: <?php echo json_encode($overdueByProgramData); ?> }
    };

    let monthlyBorrowingChart, genrePopularityChart, bookStatusChart, overdueByProgramChart;

    // --- FUNCTION TO GET THEME-AWARE OPTIONS ---
    const getThemeOptions = () => {
        const isDarkMode = document.documentElement.classList.contains('dark');
        
        // ✨ Gridlines and text colors are now theme-aware
        const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)'; // This makes the gridlines "a little bit white" in dark mode
        const textColor = isDarkMode ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)';
        const pieBorderColor = isDarkMode ? '#1e293b' : '#fff'; // Matches dark card background

        return { gridColor, textColor, pieBorderColor };
    };

    // --- FUNCTION TO RENDER/UPDATE ALL CHARTS (Improved Legend) ---
const renderCharts = () => {
    const options = getThemeOptions();
    const chartColors = {
        red: 'rgb(220, 38, 38)', redRgba: 'rgba(220, 38, 38, 0.3)',
        blue: 'rgb(59, 130, 246)', blueRgba: 'rgba(59, 130, 246, 0.3)',
        green: 'rgba(29, 83, 49, 1)', greenRgba: 'rgba(22, 163, 74, 0.3)',
        yellow: 'rgb(245, 158, 11)', yellowRgba: 'rgba(245, 158, 11, 0.3)',
        purple: 'rgb(139, 92, 246)', purpleRgba: 'rgba(139, 92, 246, 0.3)'
    };

    // --- Standard Legend Configuration for Consistency ---
    const standardLegendConfig = {
        display: true, 
        position: 'bottom', 
        labels: {
            color: options.textColor,
            usePointStyle: true, 
            pointStyle: 'rectRounded', // Consistent Shape
            borderRadius: 4, 
            boxWidth: 20, // Consistent Size (Width)
            boxHeight: 12, // Consistent Size (Height)
            font: { size: 13, weight: 'normal' }
        },
        padding: 20
    };

    // Destroy old charts before redrawing
    if (monthlyBorrowingChart) monthlyBorrowingChart.destroy();
    if (genrePopularityChart) genrePopularityChart.destroy();
    if (bookStatusChart) bookStatusChart.destroy();
    if (overdueByProgramChart) overdueByProgramChart.destroy();

    // Monthly Borrowing Chart (Line Chart)
    monthlyBorrowingChart = new Chart(document.getElementById('monthlyBorrowingChart').getContext('2d'), {
        type: 'line',
        data: { 
            labels: chartData.monthly.labels, 
            datasets: [
                // Dataset 1: Books Borrowed (Red - Gradient Area Fill)
                { 
                    label: 'Issue', 
                    data: chartData.monthly.borrow, 
                    borderColor: chartColors.red, 
                    borderWidth: 3,
                    tension: 0.5, 
                    pointRadius: 5, 
                    pointBorderColor: 'white',
                    pointBorderWidth: 2,
                    pointHoverRadius: 7,
                    fill: true, // Area fill ENABLED
                    backgroundColor: (context) => {
                        // Dynamic Gradient for RED Area Fill
                        const ctx = context.chart.ctx;
                        const gradient = ctx.createLinearGradient(0, 0, 0, context.chart.height);
                        gradient.addColorStop(0, chartColors.redRgba); 
                        gradient.addColorStop(1, 'rgba(220, 38, 38, 0)'); // Fades to transparent
                        return gradient;
                    },
                    pointStyle: 'rectRounded',
                    pointBackgroundColor: chartColors.red,
                },
                // Dataset 2: Books Returned (Blue - Gradient Area Fill ADDED HERE)
                { 
                    label: 'Return', 
                    data: chartData.monthly.return, 
                    borderColor: chartColors.blue, 
                    borderWidth: 3,
                    tension: 0.5, 
                    pointRadius: 5,
                    pointBorderColor: 'white',
                    pointBorderWidth: 2,
                    pointHoverRadius: 7,
                    fill: true, // *** CHANGED: Enable area fill for blue line ***
                    backgroundColor: (context) => {
                        // Dynamic Gradient for BLUE Area Fill (NEW)
                        const ctx = context.chart.ctx;
                        const gradient = ctx.createLinearGradient(0, 0, 0, context.chart.height);
                        gradient.addColorStop(0, chartColors.blueRgba); 
                        gradient.addColorStop(1, 'rgba(59, 130, 246, 0)'); // Fades to transparent
                        return gradient;
                    },
                    pointStyle: 'rectRounded',
                    pointBackgroundColor: chartColors.blue,
                }
            ] 
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            interaction: {
                mode: 'index', 
                intersect: false,
            },
            scales: { 
                y: { 
                    beginAtZero: true, 
                    grid: { color: options.gridColor, drawBorder: false }, 
                    ticks: { color: options.textColor } 
                }, 
                x: { 
                    grid: { color: options.gridColor, drawOnChartArea: false, drawBorder: false }, 
                    ticks: { color: options.textColor } 
                } 
            }, 
            plugins: { 
                legend: standardLegendConfig,
                tooltip: {
                    enabled: true, mode: 'index', intersect: false,
                    // *** Fixed Dark Tooltip Colors ***
                    backgroundColor: '#222', 
                    titleColor: 'white',
                    bodyColor: 'white', 
                    padding: 12,
                    titleFont: { weight: 'bold' } 
                }
            }
        }
    });

    // Genre Popularity Chart (Doughnut Chart)
    genrePopularityChart = new Chart(document.getElementById('genrePopularityChart').getContext('2d'), {
        type: 'doughnut',
        data: { labels: chartData.genre.labels, datasets: [{ data: chartData.genre.data, backgroundColor: [chartColors.red, chartColors.blue, chartColors.yellow, chartColors.green, chartColors.purple], borderColor: options.pieBorderColor, borderWidth: 4 }] },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            plugins: { 
                // Applying the consistent legend style
                legend: standardLegendConfig 
            }, 
            hoverOffset: 20 
        }
    });

    // Book Status Chart (Pie Chart)
    bookStatusChart = new Chart(document.getElementById('bookStatusChart').getContext('2d'), {
        type: 'pie',
        data: { labels: chartData.bookStatus.labels, datasets: [{ data: chartData.bookStatus.data, backgroundColor: [chartColors.green, chartColors.blue, chartColors.yellow], borderColor: options.pieBorderColor, borderWidth: 4 }] },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            plugins: { 
                // Applying the consistent legend style
                legend: standardLegendConfig
            }, 
            hoverOffset: 20 
        }
    });

    overdueByProgramChart = new Chart(document.getElementById('overdueByProgramChart').getContext('2d'), {
        type: 'bar',
        data: { labels: chartData.overdue.labels, datasets: [{ label: 'Overdue Books', data: chartData.overdue.data, backgroundColor: chartColors.yellowRgba, borderColor: chartColors.yellow, borderWidth: 2 }] },
        options: { 
            indexAxis: 'y', 
            responsive: true, 
            maintainAspectRatio: false, 
            scales: { 
                x: { 
                    beginAtZero: true, 
                    grid: { color: options.gridColor }, 
                    ticks: { color: options.textColor, stepSize: 1 } 
                }, 
                y: { 
                    grid: { color: options.gridColor }, 
                    // FIX: Reduce font size for Y-axis labels to prevent overlap
                    ticks: { color: options.textColor, font: { size: 10 } } 
                } 
            }, 
            plugins: { 
                // Legend is kept hidden (display: false) as it only has one dataset.
                legend: { display: false } 
            } 
        }
    });
};

    

    // --- INITIAL RENDER ---
    renderCharts();

    // --- LISTEN FOR THEME CHANGES ---
    const themeToggle = document.getElementById('theme-toggle-checkbox');
    if(themeToggle) {
        themeToggle.addEventListener('change', () => {
            // Re-render all charts with new theme options after a short delay
            setTimeout(renderCharts, 50); 
        });
    }

    const modal = document.getElementById('details-modal');
    const backdrop = document.getElementById('details-modal-backdrop');
    const closeBtn = document.getElementById('modal-close-btn');
    const modalTitle = document.getElementById('modal-title');
    const modalContent = document.getElementById('modal-content');
    const loadingHTML = modalContent.innerHTML;

    function showModal(sizeClass = 'max-w-xl') {
        // Reset and set the specific size
        modal.classList.remove('max-w-xl', 'max-w-2xl', 'max-w-4xl');
        modal.classList.add(sizeClass);

        backdrop.classList.remove('hidden');
        modal.classList.remove('hidden');
        setTimeout(() => {
            backdrop.classList.add('opacity-100');
            modal.classList.remove('scale-95', 'opacity-0');
        }, 10);
    }

    function hideModal() {
        backdrop.classList.remove('opacity-100');
        modal.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            backdrop.classList.add('hidden');
            modal.classList.add('hidden');
            modalContent.innerHTML = loadingHTML;
            if(lucide) lucide.createIcons();
        }, 300);
    }

    // Event listener for all view-details-btn elements (Books and Students)
   // Event listener for all view-details-btn elements (Books and Students)
    document.body.addEventListener('click', async (event) => {
        if (event.target.closest('.view-details-btn')) {
            const button = event.target.closest('.view-details-btn');
            const type = button.dataset.type;
            const id = button.dataset.id;
            
            if (!id) return;
            
            // REMOVED: showModal(); // showModal is now called inside the populate functions

            try {
                // Use the API to fetch details (get_book_details.php or get_student_details.php)
                const response = await fetch(`api/get_${type}_details.php?id=${id}`);
                if (!response.ok) throw new Error('Network response was not ok.');
                
                const data = await response.json();
                if (data.error) throw new Error(data.error);

                // Populate modal based on type (calls showModal internally)
                if (type === 'student') {
                    populateStudentModal(data);
                } else if (type === 'book') {
                    populateBookModal(data);
                }

            } catch (error) {
                // Call showModal here only if it failed to load, using a default small size
                showModal('max-w-md'); 

                modalContent.innerHTML = `<div class="text-center py-10 text-red-500">
                                            <i data-lucide="alert-circle" class="w-12 h-12 mx-auto"></i>
                                            <p class="mt-4 font-semibold">Failed to load details.</p>
                                            <p class="text-sm text-secondary">${error.message}</p>
                                         </div>`;
                if(lucide) lucide.createIcons();
            }
        }
    });

    

    
// Function to populate the modal with student data (FINAL SCROLL-FREE UI - Height Consistent)
function populateStudentModal(data) {
    modalTitle.textContent = 'Student Profile';
    const student = data.student_details;
    
    // Set width to max-w-2xl
    if (typeof showModal === 'function') {
        showModal('max-w-2xl'); 
    }

    // Determine the student photo URL with local fallback
    let studentPhoto = student.image;

    if (!studentPhoto) {
        if (student.gender && student.gender.toLowerCase() === 'female') {
            studentPhoto = 'pictures/girl.png';
        } else {
            studentPhoto = 'pictures/boy.png'; 
        }
    }
    if (!studentPhoto) {
         studentPhoto = `https://ui-avatars.com/api/?name=${student.first_name}+${student.last_name}&background=random`;
    }

    // Get the student ID for the "View Full History" link
    const studentIdOid = student._id && student._id.$oid ? student._id.$oid : student.student_no;
    
    let mostBorrowedHTML = `
        <p class="text-secondary">No borrowing history found.</p>`;

    if (data.most_borrowed_book) {
        // Tighter packing for the book details section
        mostBorrowedHTML = `
            <div class="flex items-start gap-4">
                <img src="${data.most_borrowed_book.thumbnail || 'https://placehold.co/40x64'}" class="w-10 h-16 rounded object-cover flex-shrink-0 border border-theme">
                <div class="flex-1 pt-1"> 
                    <p class="font-bold text-text">${data.most_borrowed_book.title}</p>
                    <p class="text-sm text-secondary">Borrowed ${data.most_borrowed_book.borrow_count} time(s)</p>
                    <a href="student_borrow_history.php?id=${studentIdOid}" class="text-sm font-medium text-red-600 hover:underline mt-1 inline-block">View Full History</a>
                </div>
            </div>
        `;
    }

    modalContent.innerHTML = `
        <div class="p-0">
            <div class="flex items-start gap-6 pt-1 pb-2"> 
                <img src="${studentPhoto}" alt="Student Photo" class="w-24 h-24 rounded-full object-cover border-4 border-theme shadow-md flex-shrink-0">
                
                <div class="flex-1 space-y-1 pt-1"> 
                    <h4 class="text-2xl font-bold text-text">${student.first_name} ${student.last_name}</h4>
                    
                    <div class="space-y-0.5 text-sm">
                        <div class="flex items-center gap-2">
                            <i data-lucide="scan-line" class="w-4 h-4 text-blue-500 flex-shrink-0"></i>
                            <p class="text-secondary">Student No: <span class="text-text font-medium">${student.student_no}</span></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <i data-lucide="graduation-cap" class="w-4 h-4 text-green-500 flex-shrink-0"></i>
                            <p class="text-secondary">Program: <span class="text-text font-medium">${student.program}</span></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <i data-lucide="mail" class="w-4 h-4 text-indigo-500 flex-shrink-0"></i>
                            <p class="text-secondary">Email: <span class="text-text font-medium">${student.email}</span></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <i data-lucide="${student.gender && student.gender.toLowerCase() === 'female' ? 'user-circle-2' : 'user'}" class="w-4 h-4 text-pink-500 flex-shrink-0"></i>
                            <p class="text-secondary">Gender: <span class="text-text font-medium">${student.gender}</span></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <hr class="my-3 border-theme">
            
            <div class="grid grid-cols-2 gap-4">
                
                <div>
                    <h5 class="font-bold text-lg text-text flex items-center gap-2 mb-2">
                        <i data-lucide="bar-chart-3" class="w-5 h-5 text-yellow-500"></i> Borrowing Stats
                    </h5>
                    <div class="bg-body p-4 rounded-lg border border-theme h-32 flex flex-col justify-start"> 
                        <p class="text-secondary text-sm">Total Books Borrowed:</p>
                        <p class="font-extrabold text-4xl text-red-600">${data.total_borrows}</p>
                    </div>
                </div>
                
                <div>
                    <h5 class="font-bold text-lg text-text flex items-center gap-2 mb-2">
                        <i data-lucide="bookmark" class="w-5 h-5 text-red-600"></i> Most Borrowed Book
                    </h5>
                    <div class="bg-body p-4 rounded-lg border border-theme h-32">
                        ${mostBorrowedHTML}
                    </div>
                </div>
                
            </div>
        </div>
    `;
    if(lucide) lucide.createIcons(); 
}


// Function to populate the modal with book data
function populateBookModal(data) {
    // Safety check for critical elements
    if (!modalTitle || !modalContent) return;

    modalTitle.textContent = 'Book Details';

    showModal('max-w-4xl');
    
    const book = data.book_details;

    // Safely format the authors list
    const authorsText = Array.isArray(book.authors) 
        ? book.authors.join(', ') 
        : (book.authors || 'N/A');

// --- Top Borrower HTML ---
let topBorrowerHTML = `<p class="text-secondary text-sm">No borrowing history found.</p>`;
if(data.top_borrower) {
    const borrower = data.top_borrower;
    
    // Check if the uploaded image path exists (e.g., 'uploads/...')
    const uploadedPath = borrower.image;
    
    // Determine the final photo URL
    let finalPhotoUrl = uploadedPath;

    // If no custom image, use gender fallback from the 'pictures' folder
    if (!finalPhotoUrl) {
        if (borrower.gender && borrower.gender.toLowerCase() === 'female') {
            finalPhotoUrl = 'pictures/girl.png';
        } else {
            // Default to male if gender is male, or if gender is not provided/unknown
            finalPhotoUrl = 'pictures/boy.png'; 
        }
    }
    
    // For safety, use the API-provided photoUrl as a secondary fallback 
    if (!finalPhotoUrl) {
        finalPhotoUrl = borrower.photoUrl || `https://ui-avatars.com/api/?name=${borrower.first_name}+${borrower.last_name}&background=random`;
    }

    // Now use the final calculated URL
    const borrowerPhoto = finalPhotoUrl;
    
    topBorrowerHTML = `
        <div class="flex items-center gap-4">
            <img src="${borrowerPhoto}" class="w-10 h-10 rounded-full object-cover border border-theme">
            <div>
                <p class="font-bold text-text">${borrower.first_name} ${borrower.last_name}</p>
                <p class="text-xs text-secondary">Borrowed ${borrower.borrow_count} time(s)</p>
            </div>
        </div>
    `;
}

    // --- Book Status & Quantity Display ---
    const stockQuantity = book.quantity || 0;
    const statusText = stockQuantity > 0 ? 'Available' : (book.status || 'On Loan');
    
const statusClass = stockQuantity > 0 
    ? 'bg-green-100 text-green-800 dark:bg-green-700 dark:text-green-100' 
    : 'bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100';
    
    // --- NEW & MODERN MODAL CONTENT ---
    modalContent.innerHTML = `
        <div class="flex flex-col md:flex-row items-start gap-8">
            <div class="flex-shrink-0 w-36 space-y-3">
                <img src="${book.thumbnail || 'https://placehold.co/150x220/E2E8F0/4A5568?text=No+Cover'}" alt="Book Cover" class="w-full h-auto rounded-lg shadow-xl border-4 border-theme">
                <div class="text-center">
                    <span class="inline-flex items-center px-3 py-1 text-xs font-bold rounded-full ${statusClass}">
                        <i data-lucide="${stockQuantity > 0 ? 'check-circle' : 'x-circle'}" class="w-3 h-3 mr-1"></i>
                        ${statusText}
                    </span>
                    <p class="text-sm text-secondary mt-2">In Stock: <strong class="text-text">${stockQuantity}</strong></p>
                </div>
            </div>
            
            <div class="flex-1 space-y-4">
                <h4 class="text-3xl font-extrabold text-text leading-snug">${book.title}</h4>
                <p class="text-xl font-medium text-secondary">by ${authorsText}</p>
                
                <div class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <div class="flex items-center gap-2">
                        <i data-lucide="scan-line" class="w-4 h-4 text-[var(--accent-color)]"></i>
                        <span class="text-secondary font-semibold">ISBN:</span>
                        <span class="font-medium text-text">${book.isbn || 'N/A'}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <i data-lucide="calendar-check" class="w-4 h-4 text-[var(--accent-color)]"></i>
                        <span class="text-secondary font-semibold">Published:</span>
                        <span class="font-medium text-text">${book.published_date || 'N/A'}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <i data-lucide="printer" class="w-4 h-4 text-[var(--accent-color)]"></i>
                        <span class="text-secondary font-semibold">Publisher:</span>
                        <span class="font-medium text-text">${book.publisher || 'N/A'}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <i data-lucide="type" class="w-4 h-4 text-[var(--accent-color)]"></i>
                        <span class="text-secondary font-semibold">Language:</span>
                        <span class="font-medium text-text">${book.language || 'N/A'}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <i data-lucide="book-open-text" class="w-4 h-4 text-[var(--accent-color)]"></i>
                        <span class="text-secondary font-semibold">Pages:</span>
                        <span class="font-medium text-text">${book.page_count || 'N/A'}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <i data-lucide="tags" class="w-4 h-4 text-[var(--accent-color)]"></i>
                        <span class="text-secondary font-semibold">Genre:</span>
                        <span class="font-medium text-text">${book.genre || 'N/A'}</span>
                    </div>
                </div>
            </div>
        </div>
        
        <hr class="my-6 border-theme">

        <div class="space-y-4">
            <h5 id="synopsis-header" class="synopsis-toggle-header cursor-pointer font-bold text-xl mb-3 flex items-center gap-2 text-text transition-colors duration-200 hover:text-[var(--accent-color)]">
                <span class="flex items-center gap-2">
                    <span id="synopsis-icon-wrapper" class="w-5 h-5 flex items-center justify-center"> 
                        <i id="synopsis-toggle-icon" data-lucide="chevron-down" class="w-5 h-5 text-secondary"></i>
                    </span>
                    Synopsis
                </span>
            </h5>
            
            <div id="synopsis-container" class="relative">
                <p id="synopsis-text" class="text-secondary text-sm leading-relaxed">
                    ${book.description ? book.description.replace(/\n/g, '<br>') : 'No description available for this book.'}
                </p>
            </div>
        </div>

        <hr class="my-6 border-theme">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-body p-4 rounded-xl border border-theme shadow-inner">
                <h5 class="font-bold text-lg mb-3 flex items-center gap-2 text-text">
                    <i data-lucide="trending-up" class="w-5 h-5 text-[var(--accent-color)]"></i>
                    Borrowing Stats
                </h5>
                <p class="text-secondary">Total Times Borrowed:</p>
                <span class="font-extrabold text-3xl text-[var(--accent-color)]">${data.total_borrows}</span>
            </div>
            <div class="bg-body p-4 rounded-xl border border-theme shadow-inner">
                <h5 class="font-bold text-lg mb-3 flex items-center gap-2 text-text">
                    <i data-lucide="crown" class="w-5 h-5 text-yellow-500"></i>
                    Top Borrower
                </h5>
                ${topBorrowerHTML}
            </div>
        </div>
    `;

    // 1. Re-render Lucide icons first
    if(typeof lucide !== 'undefined') lucide.createIcons();
    
    
   setTimeout(() => {
        attachSynopsisToggleLogic();
    }, 50); //
}


// Function to attach event listener and logic to the synopsis toggle
// Function to attach event listener and logic to the synopsis toggle
function attachSynopsisToggleLogic() {
    const synopsisHeader = document.getElementById('synopsis-header');
    const synopsisIconWrapper = document.getElementById('synopsis-icon-wrapper');
    const synopsisText = document.getElementById('synopsis-text'); 
    
    // Safety check: exit if required elements don't exist
    if (!synopsisHeader || !synopsisIconWrapper || !synopsisText) return; 

    // Remove previous listener before binding a new one (prevents bugs when reusing the modal)
    synopsisHeader.onclick = null; // Clear any existing click handler

    // --- Initial State Setup ---
    const lineHeight = 20;
    const maxVisibleLines = 3; 
    const maxInitialHeight = maxVisibleLines * lineHeight; 
    
    // 1. Measure full height (must be measured after icon rendering)
    synopsisText.style.maxHeight = 'none'; 
    const actualHeight = synopsisText.scrollHeight; 
    
    // Restore initial collapsed state if content is long enough
    if (actualHeight > maxInitialHeight + 5) {
        // Content is long: Enable collapse/expand view
        synopsisText.style.maxHeight = `${maxInitialHeight}px`;
        synopsisText.classList.add('collapsed'); 
        synopsisIconWrapper.classList.remove('synopsis-rotated'); 
        
        // CRITICAL FIX: Ensure the icon wrapper is explicitly set to show (flex)
        synopsisIconWrapper.style.display = 'flex'; 

    } else {
        // Content is short: No toggle needed
        synopsisText.style.maxHeight = 'none';
        synopsisHeader.style.cursor = 'default';
        
        // Hide the icon wrapper if content is short
        synopsisIconWrapper.style.display = 'none';
        return;
    }

    
    synopsisHeader.onclick = () => {
        const isCollapsed = synopsisText.classList.contains('collapsed');
        
        if (isCollapsed) {
            // EXPAND
            synopsisText.style.maxHeight = `${actualHeight + 20}px`; 
            synopsisText.classList.remove('collapsed');
            synopsisIconWrapper.classList.add('synopsis-rotated'); 

        } else {
            // COLLAPSE
            synopsisText.style.maxHeight = `${maxInitialHeight}px`;
            synopsisText.classList.add('collapsed');
            synopsisIconWrapper.classList.remove('synopsis-rotated'); 
        }
    };
}


closeBtn.addEventListener('click', hideModal);
backdrop.addEventListener('click', hideModal);
    




const profileButton = document.getElementById('profile-dropdown-button');
const profileMenu = document.getElementById('profile-dropdown-menu');

if (profileButton && profileMenu) {
    const showDropdown = () => {
        profileMenu.classList.remove('hidden');
        setTimeout(() => {
            profileMenu.classList.remove('opacity-0', '-translate-y-2');
        }, 10);
    };

    const hideDropdown = () => {
        profileMenu.classList.add('opacity-0', '-translate-y-2');
        setTimeout(() => {
            profileMenu.classList.add('hidden');
        }, 300);
    };

    profileButton.addEventListener('click', (event) => {
        event.stopPropagation();
        if (profileMenu.classList.contains('hidden')) {
            showDropdown();
        } else {
            hideDropdown();
        }
    });

    document.addEventListener('click', (event) => {
        if (!profileMenu.classList.contains('hidden') && !profileMenu.contains(event.target) && !profileButton.contains(event.target)) {
            hideDropdown();
        }
    });
}

});
</script>
<?php
require_once __DIR__ . '/templates/footer.php';
?>