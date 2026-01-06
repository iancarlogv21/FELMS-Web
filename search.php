<?php
// search.php

session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$pageTitle = 'Search Results - FELMS';
// FIX: Set currentPage for the sidebar highlight
$currentPage = 'search';

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php'; // Required for getStudentPhotoUrl()

$query = $_GET['q'] ?? '';
$results = [
    'pages' => [],
    'students' => [],
    'books' => [],
    'borrows' => [],
];
$db_error = null;

// Hardcoded list of pages for navigation search
$available_pages = [
    'dashboard' => 'dashboard.php',
    'books' => 'books.php',
    'students' => 'student.php',
    'borrow' => 'borrow.php',
    'return' => 'return.php',
    'attendance' => 'attendance.php',     
    'activity log' => 'activitylog.php',    
    'notifications' => 'notifications.php' 
];

if (!empty($query)) {
    // 1. Search Pages
    foreach ($available_pages as $name => $url) {
        if (stripos($name, $query) !== false) {
            $results['pages'][] = ['name' => ucfirst($name), 'url' => $url];
        }
    }

    // 2. Search Database
    try {
        $dbInstance = Database::getInstance();
        // Allow the query to match anywhere in the field
        $regex = new MongoDB\BSON\Regex($query, 'i'); 

        // Search Students
        $studentsCollection = $dbInstance->students();
        $studentFilter = [
            '$or' => [
                ['first_name' => $regex],
                ['last_name' => $regex],
                ['student_no' => $regex]
            ]
        ];
        $results['students'] = iterator_to_array($studentsCollection->find($studentFilter, ['limit' => 20])); 

        // Search Books (Added description search)
        $booksCollection = $dbInstance->books();
        $bookFilter = [
            '$or' => [
                ['title' => $regex],
                ['authors' => $regex],
                ['isbn' => $regex],
                ['publisher' => $regex],
                ['description' => $regex]
            ]
        ];
        $results['books'] = iterator_to_array($booksCollection->find($bookFilter, ['limit' => 20])); 

        // Search Borrows/Returns by ID 
        $borrowCollection = $dbInstance->borrows();
        if (preg_match('/^[a-f\d]{24}$/i', $query)) {
            try {
                $borrowFilter = ['_id' => new MongoDB\BSON\ObjectId($query)];
                $borrowDoc = $borrowCollection->findOne($borrowFilter);
                if ($borrowDoc) $results['borrows'][] = $borrowDoc;
            } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
                // Ignore if the query looked like an ObjectID but was invalid
            }
        }

    } catch (Exception $e) {
        $db_error = "Database Search Error: " . $e->getMessage();
    }
}

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

/* The FIX for Icon Rotation */
#synopsis-icon-wrapper svg {
    transition: transform 0.3s ease-in-out; 
    transform: rotate(0deg); /* Default state: Arrow down */
}

.synopsis-rotated svg {
    transform: rotate(-180deg) !important; /* Rotates to arrow UP */
}
</style>

<main id="main-content" class="flex-1 p-6 md:p-10 overflow-y-auto">
    <header class="mb-8">
        <h1 class="text-3xl lg:text-4xl font-bold">Search Results</h1>
        <p class="text-secondary mt-1">Found results for: <span class="font-semibold text-text"><?php echo htmlspecialchars($query); ?></span></p>
    </header>

    <?php if ($db_error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg shadow-sm" role="alert">
            <p class="font-bold">Database Error</p>
            <p><?php echo htmlspecialchars($db_error); ?></p>
        </div>
    <?php endif; ?>

    <?php if (empty($results['pages']) && empty($results['students']) && empty($results['books']) && empty($results['borrows'])): ?>
        <div class="text-center py-16">
            <i data-lucide="search-slash" class="w-16 h-16 mx-auto text-secondary mb-4"></i>
            <h2 class="text-2xl font-semibold">No Results Found</h2>
            <p class="text-secondary mt-2">We couldn't find anything matching your search. Please try a different term.</p>
        </div>
    <?php else: ?>
        <div class="space-y-10">
            <?php if (!empty($results['pages'])): ?>
            <div>
                <h2 class="text-xl font-semibold mb-4 border-b pb-2">Navigation Links (<?php echo count($results['pages']); ?>)</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <?php foreach ($results['pages'] as $page): ?>
                    <a href="<?php echo $page['url']; ?>" class="flex items-center gap-3 p-4 bg-card border border-theme rounded-lg hover:shadow-md hover:border-[var(--accent-color)] transition-all">
                        <i data-lucide="link" class="w-5 h-5 text-[var(--accent-color)]"></i>
                        <span class="font-medium"><?php echo $page['name']; ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($results['students'])): ?>
            <div>
                <h2 class="text-xl font-semibold mb-4 border-b pb-2">Students (<?php echo count($results['students']); ?>)</h2>
                <div class="bg-card border border-theme rounded-lg overflow-hidden">
                    <ul class="divide-y divide-theme">
                        <?php foreach ($results['students'] as $student): ?>
                        <li class="p-4 flex items-center justify-between hover:bg-body">
                            <div class="flex items-center gap-4">
                                <img src="<?php echo htmlspecialchars(getStudentPhotoUrl($student)); ?>" class="w-10 h-10 rounded-full object-cover">
                                <div>
                                    <p class="font-semibold"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></p>
                                    <p class="text-sm text-secondary"><?php echo htmlspecialchars($student['student_no'] ?? 'N/A'); ?> / <?php echo htmlspecialchars($student['program'] ?? 'N/A'); ?></p>
                                </div>
                            </div>
                            <button type="button" class="view-details-btn text-sm font-medium text-[var(--accent-color)] hover:underline" data-type="student" data-id="<?php echo htmlspecialchars($student['_id']); ?>">View Details</button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($results['books'])): ?>
            <div>
                <h2 class="text-xl font-semibold mb-4 border-b pb-2">Books (<?php echo count($results['books']); ?>)</h2>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <?php foreach ($results['books'] as $book): 
                        // FIX: Ensure authors is handled safely for BSONArray or array
                        $authors = $book['authors'] ?? null;
                        if ($authors instanceof \MongoDB\Model\BSONArray || is_array($authors)) {
                            // Safely convert MongoDB BSONArray or regular array to a comma-separated string
                            $authorsText = implode(', ', iterator_to_array($authors));
                        } else {
                            // Handle if it's already a string or null
                            $authorsText = $authors ?? 'N/A';
                        }
                        
                        // Truncate description for snippet
                        $descriptionSnippet = substr($book['description'] ?? 'No description available.', 0, 150);
                        if (strlen($book['description'] ?? '') > 150) {
                            $descriptionSnippet .= '...';
                        }
                        // Status styling
                        $stockQuantity = $book['quantity'] ?? 0;
                        $stockClass = $stockQuantity > 0 ? 'text-green-600' : 'text-red-600';
                    ?>
                    <div class="bg-card border border-theme rounded-lg p-4 shadow-sm flex gap-4 transition-all hover:shadow-lg hover:border-[var(--accent-color)]">
                        <img src="<?php echo htmlspecialchars($book['thumbnail'] ?? 'https://placehold.co/80x120/E2E8F0/4A5568?text=Book'); ?>" class="w-20 h-32 object-cover rounded flex-shrink-0 border border-theme">
                        <div class="flex-1 space-y-1">
                            <h3 class="font-bold text-lg leading-tight text-text"><?php echo htmlspecialchars($book['title'] ?? 'Untitled'); ?></h3>
                            <p class="text-sm text-secondary">by <?php echo htmlspecialchars($authorsText); ?></p>
                            <p class="text-xs text-secondary italic">ISBN: <?php echo htmlspecialchars($book['isbn'] ?? 'N/A'); ?></p>
                            
                            <p class="text-sm text-text mt-2 mb-2 leading-relaxed line-clamp-2"><?php echo htmlspecialchars($descriptionSnippet); ?></p>
                            
                            <div class="flex justify-between items-center pt-2 border-t border-theme-light">
                                <span class="text-sm font-medium <?php echo $stockClass; ?>">
                                    <?php echo $stockQuantity; ?> In Stock
                                </span>
                                <button type="button" class="view-details-btn text-sm font-medium text-[var(--accent-color)] hover:underline" data-type="book" data-id="<?php echo htmlspecialchars($book['_id']); ?>">View Full Details</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($results['borrows'])): ?>
            <div>
                <h2 class="text-xl font-semibold mb-4 border-b pb-2">Borrow / Return Records (<?php echo count($results['borrows']); ?>)</h2>
                <div class="bg-card border border-theme rounded-lg overflow-hidden">
                    <ul class="divide-y divide-theme">
                        <?php foreach ($results['borrows'] as $borrow): ?>
                        <li class="p-4 flex items-center justify-between hover:bg-body">
                            <div>
                                <p class="font-semibold">Record ID: <?php echo htmlspecialchars($borrow['_id']); ?></p>
                                <p class="text-sm text-secondary">Student No: <?php echo htmlspecialchars($borrow['student_no']); ?> | ISBN: <?php echo htmlspecialchars($borrow['isbn']); ?></p>
                            </div>
                            <a href="<?php echo $borrow['return_date'] ? 'return.php' : 'borrow.php'; ?>" class="text-sm font-medium text-[var(--accent-color)] hover:underline">View Records</a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<?php
require_once __DIR__ . '/templates/footer.php';
?>

<div id="details-modal-backdrop" class="fixed inset-0 bg-black bg-opacity-60 z-40 hidden transition-opacity duration-300 ease-in-out"></div>
<div id="details-modal" class="fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-2xl bg-card border border-theme rounded-xl shadow-2xl z-50 hidden transform transition-all duration-300 ease-in-out scale-95 opacity-0">
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
    // --- Modal Variables (Must be defined globally or early) ---
    const modal = document.getElementById('details-modal');
    const backdrop = document.getElementById('details-modal-backdrop');
    const closeBtn = document.getElementById('modal-close-btn');
    const modalTitle = document.getElementById('modal-title');
    const modalContent = document.getElementById('modal-content');
    const loadingHTML = modalContent.innerHTML; 

    // --- Modal Utility Functions ---

    /**
     * Shows the modal and applies the correct size class.
     * @param {string} [sizeClass='max-w-2xl'] - The size utility class (e.g., 'max-w-xl', 'max-w-4xl').
     */
    function showModal(sizeClass = 'max-w-2xl') {
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
            modalContent.innerHTML = loadingHTML; // Reset to loading spinner
            if (window.lucide) window.lucide.createIcons();
        }, 300);
    }
    
    // --- Detail Population Functions ---
    
    function populateStudentModal(data) {
        modalTitle.textContent = 'Student Profile';
        const student = data.student_details;
        
        showModal('max-w-2xl'); 

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

        const studentIdOid = student._id && student._id.$oid ? student._id.$oid : student.student_no;
        
        let mostBorrowedHTML = `<p class="text-secondary">No borrowing history found.</p>`;

        if (data.most_borrowed_book) {
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
        if(window.lucide) window.lucide.createIcons();
    }


    function populateBookModal(data) {
        modalTitle.textContent = 'Book Details';
        const book = data.book_details;
        
        // Use max-w-4xl size for book details
        showModal('max-w-4xl'); 
        
        // Safely format the authors list
        const authorsText = Array.isArray(book.authors) 
            ? book.authors.join(', ') 
            : (book.authors || 'N/A');

        // --- Top Borrower HTML ---
        let topBorrowerHTML = `<p class="text-secondary text-sm">This book has not been borrowed yet.</p>`;
        if(data.top_borrower) {
            const borrower = data.top_borrower;
            const borrowerPhoto = borrower.image || (borrower.gender && borrower.gender.toLowerCase() === 'female' ? 'pictures/girl.png' : 'pictures/boy.png');
            
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
        if(window.lucide) window.lucide.createIcons();
        
        // 2. Attach the collapse/expand logic after a short delay
        setTimeout(() => {
            attachSynopsisToggleLogic();
        }, 50); 
    }

    // Function to attach event listener and logic to the synopsis toggle
    function attachSynopsisToggleLogic() {
        const synopsisHeader = document.getElementById('synopsis-header');
        const synopsisIconWrapper = document.getElementById('synopsis-icon-wrapper');
        const synopsisText = document.getElementById('synopsis-text'); 
        
        if (!synopsisHeader || !synopsisIconWrapper || !synopsisText) return; 

        synopsisHeader.onclick = null; 

        const lineHeight = 20;
        const maxVisibleLines = 3; 
        const maxInitialHeight = maxVisibleLines * lineHeight; 
        
        // 1. Measure full height 
        synopsisText.style.maxHeight = 'none'; 
        const actualHeight = synopsisText.scrollHeight; 
        
        // Restore initial collapsed state if content is long enough
        if (actualHeight > maxInitialHeight + 5) {
            // Content is long: Enable collapse/expand view
            synopsisText.style.maxHeight = `${maxInitialHeight}px`;
            synopsisText.classList.add('collapsed'); 
            synopsisIconWrapper.classList.remove('synopsis-rotated'); 
            synopsisIconWrapper.style.display = 'flex'; 

        } else {
            // Content is short: No toggle needed
            synopsisText.style.maxHeight = 'none';
            synopsisHeader.style.cursor = 'default';
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
    
    // --- Event Listeners ---
    document.addEventListener('DOMContentLoaded', () => {
        // Event listener for view-details-btn elements
        document.body.addEventListener('click', async (event) => {
            const button = event.target.closest('.view-details-btn');
            if (button) {
                const type = button.dataset.type;
                const id = button.dataset.id;
                
                // Reset content and show modal first (default size)
                modalContent.innerHTML = loadingHTML;
                showModal(); 
                
                try {
                    const response = await fetch(`api/get_${type}_details.php?id=${id}`);
                    if (!response.ok) throw new Error('Network response was not ok.');
                    
                    const data = await response.json();
                    if (data.error) throw new Error(data.error);

                    // Populate modal based on type (which calls showModal again with correct size)
                    if (type === 'student') {
                        populateStudentModal(data);
                    } else if (type === 'book') {
                        populateBookModal(data);
                    }

                } catch (error) {
                    modalContent.innerHTML = `<div class="text-center py-10 text-red-500">
                                                <i data-lucide="alert-circle" class="w-12 h-12 mx-auto"></i>
                                                <p class="mt-4 font-semibold">Failed to load details.</p>
                                                <p class="text-sm text-secondary">${error.message}</p>
                                            </div>`;
                    if(window.lucide) window.lucide.createIcons();
                }
            }
        });

        // Event listeners to close the modal
        closeBtn.addEventListener('click', hideModal);
        backdrop.addEventListener('click', hideModal);
    });
</script>