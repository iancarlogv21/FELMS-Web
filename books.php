<?php
session_start();

// If the user is not logged in, redirect to the login page
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// 1. SET PAGE-SPECIFIC VARIABLES
$currentPage = 'books';
$pageTitle = 'Manage Books - FELMS';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

// --- PHP LOGIC for fetching books and genres ---
$db_error = null;
$books = [];
$genres = [];
$selected_genre = $_GET['genre'] ?? 'all';

try {
    $dbInstance = Database::getInstance();
    $addBookCollection = $dbInstance->books();

    $genreCursor = $addBookCollection->aggregate([
        ['$match' => ['genre' => ['$ne' => null, '$ne' => '']]],
        ['$group' => ['_id' => '$genre']],
        ['$sort' => ['_id' => 1]]
    ]);
    
    foreach ($genreCursor as $doc) {
        $genres[] = $doc['_id'];
    }

    $filter = [];
    if ($selected_genre !== 'all') {
        $filter['genre'] = $selected_genre;
    }
    $books = iterator_to_array($addBookCollection->find($filter, ['sort' => ['title' => 1]]));

} catch (Exception $e) {
    $db_error = "MongoDB Connection Error: " . $e->getMessage();
}

$booksToUpdate = [];
foreach ($books as &$book) {
    $isModified = false;
    
    // Ensure Title is not empty
    if (empty($book['title'])) {
        $book['title'] = 'Unknown Book (Legacy Record)';
        $booksToUpdate[(string)$book['_id']] = ['title' => $book['title']];
        $isModified = true;
    }

    // Ensure ISBN is not empty
    if (empty($book['isbn'])) {
        $book['isbn'] = 'N/A (Missing ISBN)';
        // NOTE: Use (string)$book['_id'] as the key here for consistency
        $booksToUpdate[(string)$book['_id']] = array_merge($booksToUpdate[(string)$book['_id']] ?? [], ['isbn' => $book['isbn']]);
        $isModified = true;
    }

    // Ensure thumbnail is set to an empty string if null, so the placeholder works
    if (!isset($book['thumbnail']) || is_null($book['thumbnail'])) {
        $book['thumbnail'] = ''; 
        // We won't try to update the DB for just an empty thumbnail, 
        // as the display logic already handles it with a placeholder.
    }
    
    // Convert BSON ObjectId to string for JS compatibility
    if (isset($book['_id']) && $book['_id'] instanceof MongoDB\BSON\ObjectId) {
        $book['_id'] = (string)$book['_id'];
    }
}
unset($book); // Crucial to unset the reference variable

// Batch update the database for corrected titles and ISBNs
if (!empty($booksToUpdate) && isset($addBookCollection)) { // Added isset check for robustness
    foreach ($booksToUpdate as $objectId => $fieldsToSet) {
        try {
            // Update the MongoDB document
            $addBookCollection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($objectId)],
                ['$set' => $fieldsToSet]
            );
        } catch (Exception $e) {
            // Handle update errors if necessary
            error_log("Failed to patch book record {$objectId}: " . $e->getMessage());
        }
    }
}




// 2. INCLUDE THE HEADER
require_once __DIR__ . '/templates/header.php';

// 3. INCLUDE THE SIDEBAR
require_once __DIR__ . '/templates/sidebar.php';
?>

<style>
    /* Base Form and Button Styles */
    .form-label { 
        display: block; margin-bottom: 0.25rem; font-size: 0.875rem; 
        font-weight: 500; color: #475569;
    }
    .dark .form-label { color: #e2e8f0; }

    .form-input { 
        display: block; width: 100%; border: 1px solid #e2e8f0; border-radius: 0.5rem;
        padding: 0.75rem 1rem; background-color: #f8fafc; color: #1e293b;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .dark .form-input {
        background-color: #334155; border-color: #475569; color: #f1f5f9;
    }
    .form-input::placeholder { color: #94a3b8; }
    .dark .form-input::placeholder { color: #94a3b8; }
    .form-input:focus { border-color: #38bdf8; box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.4); outline: none; }
    .dark .form-input:focus { border-color: #38bdf8; }
    
    .btn {
        display: inline-flex; align-items: center; justify-content: center;
        gap: 0.5rem; padding: 0.75rem 1.25rem; border-radius: 0.5rem;
        font-weight: 600; transition: all 0.2s; cursor: pointer; border: none; line-height: 1.25;
    }
    .btn-primary { background-color: #0ea5e9; color: white; }
    .btn-primary:hover:not(:disabled) { background-color: #0284c7; }
    .input-with-icon { padding-left: 2.75rem; }

    /* Modal Styles */
    .modal-overlay {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0, 0, 0, 0.6); display: flex; align-items: center; justify-content: center;
        z-index: 1000; opacity: 0; transition: opacity 0.3s ease; pointer-events: none;
    }
    .modal-overlay.active { opacity: 1; pointer-events: auto; }
    .modal-content {
        background: white; border-radius: 1rem; width: 90%; max-width: 900px;
        max-height: 90vh; display: flex; flex-direction: column;
        transform: scale(0.95); transition: transform 0.3s ease;
        box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.25);
    }
    .dark .modal-content { background: #1e293b; }
    .modal-overlay.active .modal-content { transform: scale(1); }
    .modal-header { padding: 1.5rem; border-bottom: 1px solid #e2e8f0; }
    .dark .modal-header { border-bottom-color: #334155; }
    .modal-body { padding: 1.5rem; overflow-y: auto; flex-grow: 1; }
    .modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; background-color: #f8fafc; }
    .dark .modal-footer { background-color: #0f172a; border-top-color: #334155; }
    .modal-close-btn { 
        background: #e2e8f0; color: #475569; border-radius: 99px;
        width: 2rem; height: 2rem; display: flex; align-items: center; justify-content: center;
    }
    .dark .modal-close-btn { background: #334155; color: #94a3b8; }

    /* Hover Effects */
    .book-card-list-item { transition: all 0.2s ease-in-out; }
    .book-card-list-item:hover {
        transform: translateY(-3px) scale(1.01);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        background-color: #ffffff;
    }
    .dark .book-card-list-item:hover {
        background-color: #334155;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
    }

    /* ✨ TEXT COLOR FIXES FOR LIGHT/DARK MODE ✨ */
    /* Panel and List titles */
    #left-panel h3, .genre-section h3, .book-card-list-item h4 {
        color: #111827; /* Near-black for light mode */
    }
    .dark #left-panel h3, .dark .genre-section h3, .dark .book-card-list-item h4 {
        color: #f1f5f9; /* Light gray for dark mode */
    }

    /* Modal Text */
    .modal-content h2, .modal-content h3, .modal-content strong {
        color: #000000;
    }
    .modal-content p, .modal-content span {
        color: #1e293b;
    }
    .dark .modal-content h2, .dark .modal-content h3, .dark .modal-content strong {
        color: #f1f5f9;
    }
    .dark .modal-content p, .dark .modal-content span {
        color: #cbd5e1;
    }

    /* --- Simple Button Styling --- */

#add-book-btn {
  /* Core Colors */
  background-color: #07a549ff; /* A vibrant, accessible blue */
  color: #ffffff; /* White text for high contrast */

  /* Optional: Extra styling for a modern look */
  border: none; /* Removes the default border */
  padding: 10px 20px; /* Adds comfortable spacing */
  border-radius: 8px; /* Rounds the corners */
  cursor: pointer; /* Changes the cursor to a pointer on hover */
  transition: background-color 0.3s ease; /* Smooth transition for hover effect */
  font-weight: bold; /* Makes the text bolder */
}

/* Add a visual effect when the user hovers over the button */
#add-book-btn:hover {
  background-color: #028342ff; /* A darker shade of blue on hover */
}

/* Add this with your other styles */
#left-panel, #library-collection-panel {
    transition: all 0.5s ease-in-out;
}



/* Add this with your other CSS styles */
.cover-wrapper {
    position: relative;
    cursor: pointer;
    overflow: hidden; /* Ensures the overlay corners are rounded */
    border-radius: 0.5rem;
}

.cover-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    color: white;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease-in-out;
    border-radius: 0.5rem;
}

.cover-wrapper:hover .cover-overlay {
    opacity: 1;
}

/* Add this to your existing <style> block in books.php */
.input-with-icon-scan { 
    padding-left: 2.5rem !important; /* Forces the text over to make room for the icon */
}

.modal-body .grid strong {
    /* Enforce alignment in case Tailwind classes on strong fail for some reason */
    display: flex !important;
    align-items: center !important;
    gap: 0.25rem; /* Equivalent to gap-1 in Tailwind for small spacing */
}

:root {
    --accent-color: #ef4444; /* Example red color for the icon */
}

/* Also ensure your dark mode or general text color definitions include a .text-text equivalent if needed */
.text-text {
    color: #1e293b; /* A standard dark color */
}
.dark .text-text {
    color: #f1f5f9; /* A standard light color */
}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<main id="main-content" class="flex-1 p-6 md:p-10 overflow-y-auto">
    <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10">
        <div>
            <h1 class="text-4xl font-bold tracking-tight">Manage Books</h1>
            <p class="text-secondary mt-2">Add, update, and organize your library's collection.</p>
        </div>
    </header>
    <div id="status-message" class="mb-6 text-center sticky top-4 z-30"></div>
    
    <div id="content-grid" class="grid grid-cols-1 lg:grid-cols-12 gap-10">
        <div id="left-panel" class="lg:col-span-4 bg-card p-8 rounded-2xl border border-theme shadow-sm self-start sticky top-6"></div>
        <div id="library-collection-panel" class="lg:col-span-8 bg-card p-8 rounded-2xl border border-theme shadow-sm"></div>
    </div>
</main>

<div id="book-preview-modal" class="modal-overlay">
    <div class="modal-content">
        <header class="modal-header flex justify-between items-center">
            <h2 id="modal-title" class="text-2xl font-bold">Book Details</h2>
            <button id="modal-close" class="modal-close-btn"><i data-lucide="x"></i></button>
        </header>
        <div class="modal-body">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="md:col-span-1">
                    <img id="modal-cover" src="https://placehold.co/300x450/e2e8f0/475569?text=Loading..." alt="Book Cover" class="w-full h-auto object-cover rounded-lg shadow-xl mb-4">
                </div>
               <div class="md:col-span-2">
    <p id="modal-authors" class="text-xl font-medium -mt-1"></p>
    
    <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm mt-4 border-t pt-4 dark:border-slate-700">

        <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
            <i data-lucide="barcode" class="w-4 h-4 text-blue-500"></i>ISBN:
        </div>
        <span id="modal-isbn" class="col-span-1 text-slate-800 dark:text-slate-100"></span>

        <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
            <i data-lucide="list-ordered" class="w-4 h-4 text-gray-500"></i>LCCN:
        </div>
        <span id="modal-lccn" class="col-span-1 text-slate-800 dark:text-slate-100"></span>

        <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
            <i data-lucide="library-big" class="w-4 h-4 text-indigo-500"></i>OCLC:
        </div>
        <span id="modal-oclc" class="col-span-1 text-slate-800 dark:text-slate-100"></span>

        <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
            <i data-lucide="book-open" class="w-4 h-4 text-green-500"></i>OLID:
        </div>
        <span id="modal-olid" class="col-span-1 text-slate-800 dark:text-slate-100"></span>
        
        <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
            <i data-lucide="building-2" class="w-4 h-4 text-amber-500"></i>Publisher:
        </div>
        <span id="modal-publisher" class="col-span-1 text-slate-800 dark:text-slate-100"></span>

        <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
            <i data-lucide="calendar" class="w-4 h-4 text-cyan-500"></i>Published:
        </div>
        <span id="modal-published" class="col-span-1 text-slate-800 dark:text-slate-100"></span>
        
        <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
            <i data-lucide="file-text" class="w-4 h-4 text-purple-500"></i>Pages:
        </div>
        <span id="modal-pages" class="col-span-1 text-slate-800 dark:text-slate-100"></span>

        <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
            <i data-lucide="type" class="w-4 h-4 text-red-500"></i>Language:
        </div>
        <span id="modal-language" class="col-span-1 text-slate-800 dark:text-slate-100"></span>

        <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
            <i data-lucide="tag" class="w-4 h-4 text-pink-500"></i>Genre:
        </div>
        <span id="modal-genre" class="col-span-1 text-slate-800 dark:text-slate-100"></span>
    </div>
    <h3 class="text-lg font-bold mt-6 border-b pb-2 mb-2 dark:border-slate-700">Description</h3>
    <p id="modal-description" class="max-h-48 overflow-y-auto text-sm leading-relaxed"></p>
    </div>
            </div>
        </div>
        <footer class="modal-footer flex justify-end">
            <button id="modal-edit-btn" class="btn btn-primary"><i data-lucide="edit-3"></i>Edit Book Details</button>
        </footer>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // --- DATA & STATE ---
    const booksData = <?php echo json_encode(array_values($books)); ?>;
    const genresData = <?php echo json_encode($genres); ?>;
    const selectedGenre = '<?php echo $selected_genre; ?>';
    let currentBookId = null;
    let currentPreviewedBook = null;

    // --- DOM ELEMENTS ---
    const leftPanel = document.getElementById('left-panel');
    const libraryPanel = document.getElementById('library-collection-panel');
    const modal = document.getElementById('book-preview-modal');
    const modalCloseBtn = document.getElementById('modal-close');
    const modalEditBtn = document.getElementById('modal-edit-btn');
    const statusMessage = document.getElementById('status-message');

    const updatePanelLayout = (leftCols, rightCols) => {
        if (leftPanel && libraryPanel) {
            // Define all possible column classes to toggle between
            const leftColClasses = ['lg:col-span-4', 'lg:col-span-6'];
            const rightColClasses = ['lg:col-span-8', 'lg:col-span-6'];

            // Remove existing column classes
            leftPanel.classList.remove(...leftColClasses);
            libraryPanel.classList.remove(...rightColClasses);

            // Add the new desired column classes
            leftPanel.classList.add(`lg:col-span-${leftCols}`);
            libraryPanel.classList.add(`lg:col-span-${rightCols}`);
        }
    };

    // --- UTILITY FUNCTIONS ---
    const showStatus = (message, color, duration = 4000) => {
        const colorClasses = { green: 'bg-green-500', red: 'bg-red-500', sky: 'bg-sky-500' };
        statusMessage.innerHTML = `<p class="font-semibold p-3 rounded-lg text-white ${colorClasses[color] || 'bg-slate-500'} shadow-md">${message}</p>`;
        if (duration) setTimeout(() => statusMessage.innerHTML = '', duration);
    };

    const populateForm = (book) => {
        const form = document.getElementById('book-form');
        if (!form) return;
        
        const accessionGroup = form.querySelector('#accession-number-group');
        const accDisplayInput = form.querySelector('[name="accession_number_display"]');
        const isbnInput = form.querySelector('[name="isbn"]');
        
        // --- Accession Number Logic in Form ---
        if (book.accession_number) {
            // If the book has an Accession Number, display it (read-only)
            accessionGroup.style.display = 'grid';
            accDisplayInput.value = book.accession_number;
            
            // Set ISBN field content based on whether an actual ISBN is present
            if (book.isbn && book.isbn !== 'N/A (Missing ISBN)' && book.isbn !== '') {
                isbnInput.value = book.isbn;
                isbnInput.placeholder = "9780743273565";
            } else {
                isbnInput.value = '';
                isbnInput.placeholder = 'N/A (Use Accession No.)';
            }
        } else {
            // Hide ACC field for new books or books with only ISBN
            accessionGroup.style.display = 'none';
            isbnInput.value = book.isbn && book.isbn !== 'N/A (Missing ISBN)' ? book.isbn : '';
            isbnInput.placeholder = '9780743273565';
        }
        
        // --- Standard Field Population (after ACC/ISBN logic) ---
        form.querySelector('[name="title"]').value = book.title || '';
        form.querySelector('[name="authors"]').value = Array.isArray(book.authors) ? book.authors.join(', ') : (book.authors || '');
        form.querySelector('[name="description"]').value = book.description || '';
        form.querySelector('[name="published_date"]').value = book.published_date || '';
        form.querySelector('[name="publisher"]').value = book.publisher || '';
        form.querySelector('[name="edition"]').value = book.edition || ''; 
        form.querySelector('[name="genre"]').value = book.genre || '';
        form.querySelector('[name="language"]').value = book.language || '';
        form.querySelector('[name="page_count"]').value = book.page_count || ''; 
        form.querySelector('[name="location"]').value = book.location || ''; 
        form.querySelector('[name="quantity"]').value = book.quantity === undefined ? 1 : book.quantity;
        form.querySelector('[name="status"]').value = book.status || 'On Shelf';
        form.querySelector('[name="thumbnail"]').value = book.thumbnail || '';
        
        const coverImg = document.getElementById('book-cover-img');
        if(coverImg) coverImg.src = book.thumbnail || `https://placehold.co/300x450/f1f5f9/475569?text=${encodeURIComponent(book.title || 'Book')}`;
    };
    // --- MODAL HANDLING ---
    const openModal = () => modal.classList.add('active');
    const closeModal = () => modal.classList.remove('active');

    // **CORE FIX: Updated showBookPreview logic for alignment and ACC/ISBN split**
    const showBookPreview = (book) => {
        currentPreviewedBook = book;
        document.getElementById('modal-title').textContent = book.title || 'Book Details';
        document.getElementById('modal-cover').src = book.thumbnail || `https://placehold.co/300x450/e2e8f0/475569?text=${encodeURIComponent(book.title || 'No Cover')}`;
        document.getElementById('modal-authors').textContent = Array.isArray(book.authors) ? book.authors.join(', ') : (book.authors || 'Unknown Author');
        
        // --- 1. Determine Identifier Display ---
        const isAccessionBook = book.accession_number && (!book.isbn || book.isbn === 'N/A' || book.isbn === 'N/A (Missing ISBN)' || book.isbn === '');
        const isbnValue = (book.isbn && book.isbn !== 'N/A (Missing ISBN)' && book.isbn !== '') ? book.isbn : 'N/A';
        const accessionValue = book.accession_number || 'N/A';
        
        // --- 2. Build the new Modal Details HTML ---
       let modalDetailsHTML = '';

// *** FIX: Separate ISBN/ACC row, maintaining 2-column structure ***
if (isAccessionBook) {
    // This uses the ACC number for the value in the first row
    modalDetailsHTML += `
        <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
            <i data-lucide="scan" class="w-4 h-4 text-red-500"></i>Accession No:
        </div>
        <span class="col-span-1 text-slate-800 dark:text-slate-100">${accessionValue}</span>
    `;
    // The second row explicitly shows ISBN as N/A
    modalDetailsHTML += `
        <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
            <i data-lucide="barcode" class="w-4 h-4 text-gray-500"></i>ISBN:
        </div>
        <span class="col-span-1 text-slate-800 dark:text-slate-100">N/A</span>
    `;
} else {
    // Standard ISBN display
    modalDetailsHTML += `
        <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
            <i data-lucide="barcode" class="w-4 h-4 text-blue-500"></i>ISBN:
        </div>
        <span class="col-span-1 text-slate-800 dark:text-slate-100">${isbnValue}</span>
    `;
}

// Add the rest of the details in the consistent 2-column grid format (Label Div / Value Span)
modalDetailsHTML += `
    <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
        <i data-lucide="building-2" class="w-4 h-4 text-amber-500"></i>Publisher:
    </div>
    <span class="col-span-1 text-slate-800 dark:text-slate-100">${book.publisher || 'N/A'}</span>
    
    <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
        <i data-lucide="calendar" class="w-4 h-4 text-cyan-500"></i>Published:
    </div>
    <span class="col-span-1 text-slate-800 dark:text-slate-100">${book.published_date || 'N/A'}</span>
    
    <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
        <i data-lucide="file-text" class="w-4 h-4 text-purple-500"></i>Pages:
    </div>
    <span class="col-span-1 text-slate-800 dark:text-slate-100">${book.page_count || 'N/A'}</span>
    
    <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
        <i data-lucide="type" class="w-4 h-4 text-red-500"></i>Language:
    </div>
    <span class="col-span-1 text-slate-800 dark:text-slate-100">${book.language || 'N/A'}</span>
    
    <div class="col-span-1 flex items-center gap-2 font-semibold text-slate-500 dark:text-slate-400">
        <i data-lucide="tag" class="w-4 h-4 text-pink-500"></i>Genre:
    </div>
    <span class="col-span-1 text-slate-800 dark:text-slate-100">${book.genre || 'N/A'}</span>
`;

        // Find the details container (the grid of 2 columns) and replace its content
        document.querySelector('.modal-body .md\\:col-span-2 .grid.grid-cols-2').innerHTML = modalDetailsHTML;
        
        // Re-render lucide icons immediately after inserting new HTML
        lucide.createIcons();

        document.getElementById('modal-description').innerHTML = book.description ? book.description.replace(/\n/g, '<br>') : 'No description available.';
        openModal();
    };
    
    modalCloseBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    modalEditBtn.addEventListener('click', () => {
        if (currentPreviewedBook) {
            closeModal();
            renderEditPanel(currentPreviewedBook, false);
        }
    });

    // --- FORM & API HANDLING (unchanged) ---
    window.handleFormSubmit = async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const action = e.submitter?.getAttribute('name') === 'action' ? e.submitter.value : null;
        if (!action) return;
        
        formData.set('action', action);
        if (currentBookId) formData.set('book_id', currentBookId);
        if (action === 'delete' && !confirm('Are you sure you want to delete this book?')) return;

        showStatus('Processing...', 'sky', 0);
        try {
            const response = await fetch('book_actions.php', { method: 'POST', body: formData });
            const result = await response.json();
            if(result.success) {
                showStatus(result.message, 'green');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showStatus(result.message || 'An error occurred.', 'red');
            }
        } catch(error) {
            showStatus('A network error occurred. Please check console.', 'red');
            console.error(error);
        }
    };
    
    const handleSync = async () => {
        const isbnInput = document.querySelector('#book-form [name="isbn"]');
        const isbn = isbnInput?.value.trim();
        if (!isbn) {
            showStatus('ISBN is required to sync details.', 'red');
            return;
        }
        showStatus('Syncing with Google...', 'sky', 0);
        try {
            const response = await fetch(`book_actions.php?action=check_and_fetch_book&isbn=${isbn}`);
            const result = await response.json();
            if(result.success && result.book) {
                populateForm(result.book);
                showStatus('Sync successful! Review the updated details.', 'green');
            } else {
                showStatus(result.message || 'Could not fetch details for this ISBN.', 'red');
            }
        } catch(error) {
            showStatus('A network error occurred during sync.', 'red');
        }
    };

    const handleFetchFromAddPanel = async () => {
        const isbnInput = document.getElementById('isbn-scanner');
        const isbn = isbnInput?.value.trim();
        if (!isbn) {
            showStatus('Please enter an ISBN to fetch.', 'red');
            return;
        }
        showStatus('Fetching book details...', 'sky', 0);
        try {
            const response = await fetch(`book_actions.php?action=check_and_fetch_book&isbn=${isbn}`);
            const result = await response.json();
            if (result.success) {
                showStatus(result.message, 'green');
                renderEditPanel(result.book, !result.exists);
                renderFetchedBookPreview(result.book);
            } else {
                showStatus(result.message || 'An error occurred.', 'red');
            }
        } catch (error) {
            showStatus('A network error occurred.', 'red');
        }
    };

    // --- PANEL RENDERING FUNCTIONS ---
    // 1. UPDATED: Added Accession Number fields
    const generateBookForm = () => `
    <form id="book-form" onsubmit="window.handleFormSubmit(event)">
        <div class="space-y-5 max-h-[calc(100vh-450px)] overflow-y-auto p-1 pr-3">
            <div><label class="form-label">Title</label><input type="text" name="title" required class="form-input" placeholder="e.g., The Great Gatsby"></div>
            <div><label class="form-label">Author(s)</label><input type="text" name="authors" placeholder="F. Scott Fitzgerald" required class="form-input"></div>
            <div><label class="form-label">Description</label><textarea name="description" rows="5" class="form-input" placeholder="A short summary of the book..."></textarea></div>
            
            <div id="accession-number-group" class="grid grid-cols-2 gap-4" style="display: none;">
                <div class="col-span-2">
                    <label class="form-label">Accession Number</label>
                    <input type="text" name="accession_number_display" class="form-input bg-gray-100 dark:bg-slate-700" readonly>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">ISBN</label><input type="text" name="isbn" class="form-input" placeholder="9780743273565"></div>
                <div><label class="form-label">Published Date</label><input type="text" name="published_date" placeholder="YYYY-MM-DD" class="form-input"></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Publisher</label><input type="text" name="publisher" class="form-input" placeholder="Charles Scribner's Sons"></div>
                <div><label class="form-label">Edition</label><input type="text" name="edition" class="form-input" placeholder="e.g., 2nd, Revised"></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Genre</label><input type="text" name="genre" class="form-input" placeholder="Fiction, Classics"></div>
                <div><label class="form-label">Language</label><input type="text" name="language" class="form-input" placeholder="en"></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Number of Pages</label><input type="number" name="page_count" min="0" class="form-input" placeholder="e.g., 350"></div>
                <div><label class="form-label">Location / Shelf</label><input type="text" name="location" class="form-input" placeholder="e.g., Fiction Aisle, Row 3"></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Quantity</label><input type="number" name="quantity" min="0" required class="form-input"></div>
                <div>
                    <label class="form-label">Status</label>
                    <select name="status" class="form-input">
                        <option>On Shelf</option>
                        <option>On Loan</option>
                        <option>Damaged</option>
                        <option>Lost</option>
                        <option>In Repair</option>
                        <option>Unavailable</option>
                    </select>
                </div>
            </div>
            <input type="hidden" name="thumbnail">
        </div>
    </form>`;
    
    // 2. UPDATED: Control visibility of Accession Number field
    const renderEditPanel = (book, isNew) => {
        updatePanelLayout(6, 6); // Expand left panel
        currentBookId = isNew ? null : (book._id?.$oid || book._id);
        
        leftPanel.innerHTML = `
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-2xl font-bold tracking-tight">${isNew ? 'Add New Book' : 'Edit Book Details'}</h3>
                <button id="cancel-edit-btn" class="text-sm font-semibold text-sky-600 hover:underline">Cancel</button>
            </div>
            <div class="flex flex-col md:flex-row gap-8 mb-6">
                <div class="md:w-1/3 flex-shrink-0">
                    <div id="cover-wrapper" class="cover-wrapper">
                        <img id="book-cover-img" src="" alt="Book Cover" class="w-full h-auto object-cover rounded-lg shadow-lg mx-auto">
                        <div class="cover-overlay">
                            <i data-lucide="upload-cloud" class="w-10 h-10 mb-2"></i>
                            <span>Upload Cover</span>
                        </div>
                    </div>
                    <input type="file" id="cover-upload-input" accept="image/*" style="display: none;">
                </div>
                <div class="md:w-2/3 flex flex-col">${generateBookForm()}</div>
            </div>
            <div class="space-y-3">
                <div class="grid grid-cols-2 gap-3" style="display: ${isNew ? 'grid' : 'none'}">
                    <button type="submit" form="book-form" name="action" value="add" id="add-book-btn" class="btn btn-success col-span-2"><i data-lucide="plus-circle"></i> Add Book to Library</button>
                </div>
                <div class="grid grid-cols-2 gap-3" style="display: ${isNew ? 'none' : 'grid'}">
                    <button type="submit" form="book-form" name="action" value="update" class="btn btn-primary"><i data-lucide="save"></i>Update</button>
                    <button id="sync-book-btn" type="button" class="btn btn-secondary"><i data-lucide="refresh-cw"></i>Sync</button>
                </div>
                <button type="submit" form="book-form" name="action" value="delete" class="w-full btn bg-transparent text-red-600 hover:bg-red-50" style="display: ${isNew ? 'none' : 'flex'}"><i data-lucide="trash-2"></i>Delete Book</button>
            </div>`;
        
        populateForm(book);
        lucide.createIcons();

        // --- NEW LOGIC for Image Upload ---
        const coverWrapper = document.getElementById('cover-wrapper');
        const coverUploadInput = document.getElementById('cover-upload-input');
        const bookCoverImg = document.getElementById('book-cover-img');
        const thumbnailInput = document.querySelector('#book-form [name="thumbnail"]');

        coverWrapper.addEventListener('click', () => {
            coverUploadInput.click(); // Trigger the hidden file input
        });

        coverUploadInput.addEventListener('change', (event) => {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onloadend = () => {
                    const base64String = reader.result;
                    bookCoverImg.src = base64String; // Show preview
                    thumbnailInput.value = base64String; // Store Base64 string in form
                };
                reader.readAsDataURL(file); // Convert image to Base64
            }
        });
        // ------------------------------------

        document.getElementById('cancel-edit-btn').addEventListener('click', () => {
            renderAddBookPanel(); 
        });

        if (!isNew) {
            document.getElementById('sync-book-btn').addEventListener('click', handleSync);
        }
    };

    const renderAddBookPanel = () => {

        updatePanelLayout(4, 8);
        currentBookId = null;
        leftPanel.innerHTML = `
            <h3 class="text-2xl font-bold tracking-tight mb-2">Add New Book</h3>
<p class="text-secondary mb-6">Quickly add a book by scanning the barcode or entering the details manually.</p>

<div class="space-y-5">
    <div>
        
        <div class="flex items-center gap-2">
            <div class="relative flex-grow">
                <i data-lucide="scan-line" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                
                <input 
                    type="text" 
                    id="isbn-scanner" 
                    placeholder="Scan or enter ISBN and press Enter" 
                    class="form-input flex-grow input-with-icon-scan"
                >
            </div>
            
            <button id="fetch-book-btn" class="btn btn-primary !p-3">
                <i data-lucide="search"></i>
            </button>
        </div>
    </div>
    
    <div class="relative flex items-center">
        <div class="flex-grow border-t border-theme dark:border-slate-700"></div>
        <span class="flex-shrink mx-4 text-secondary text-sm uppercase dark:text-slate-400">Or</span>
        <div class="flex-grow border-t border-theme dark:border-slate-700"></div>
    </div>
    
    <button id="manual-add-btn" class="w-full btn btn-accent">
        <i data-lucide="edit-3"></i>Add Manually
    </button>
</div>`;
        lucide.createIcons();
        document.getElementById('manual-add-btn').addEventListener('click', () => renderEditPanel({}, true));
        document.getElementById('fetch-book-btn').addEventListener('click', handleFetchFromAddPanel);
        document.getElementById('isbn-scanner').addEventListener('keypress', (e) => { if (e.key === 'Enter') handleFetchFromAddPanel(); });
    };

    // 4. UPDATED: Display logic for book list items
    const renderGroupedLibrary = (booksToRender) => {
        const gridContainer = document.getElementById('library-grid');
        if (!gridContainer) return;
        if (booksToRender.length === 0) {
            gridContainer.innerHTML = `<div class="text-center text-secondary py-16 dark:text-slate-400">No books were found.</div>`;
            return;
        }
        const groupedBooks = booksToRender.reduce((acc, book) => {
            const genre = book.genre || 'Uncategorized';
            if (!acc[genre]) acc[genre] = [];
            acc[genre].push(book);
            return acc;
        }, {});
        const sortedGenres = Object.keys(groupedBooks).sort();
        gridContainer.innerHTML = sortedGenres.map(genre => `
            <div class="genre-section">
                <h3 class="text-xl font-bold tracking-tight mb-4 pb-2 border-b-2 border-theme dark:border-slate-700">${genre}</h3>
                <div class="space-y-3">

                    ${groupedBooks[genre].map(book => {
                        const bookId = book._id?.$oid || book._id;
                        const authors = (Array.isArray(book.authors) ? book.authors.join(', ') : book.authors) || 'Unknown Author';
                        
                        // --- IDENTIFIER DISPLAY LOGIC FIX ---
                        let displayIdentifier = 'N/A';
                        if (book.accession_number && book.accession_number !== 'N/A') {
                            displayIdentifier = `ACC: ${book.accession_number}`;
                        } else if (book.isbn && book.isbn !== 'N/A (Missing ISBN)' && book.isbn !== 'N/A' && book.isbn !== '') {
                            displayIdentifier = `ISBN: ${book.isbn}`;
                        }
                        // --- END IDENTIFIER DISPLAY LOGIC FIX ---
                        

                        // --- START CORRECTED STATUS LOGIC (Priority Check) ---
                        let statusClass;
                        let displayStatus;
                        const quantity = book.quantity ?? 0;
                        const currentDBStatus = book.status || 'N/A';

                        if (quantity < 1) {
                            
                            statusClass = 'bg-red-500 text-white'; 
                            displayStatus = 'Unavailable';
                        } else if (currentDBStatus === 'On Shelf' || currentDBStatus === 'Unavailable' || currentDBStatus === 'On Loan') {
                            // If quantity > 0:
                            if (currentDBStatus === 'On Loan') {
                                
                                statusClass = 'bg-amber-500 text-white';
                                displayStatus = 'On Loan';
                            } else {
                               
                                statusClass = 'bg-emerald-500 text-white';
                                displayStatus = 'On Shelf';
                            }
                        } else {
                            
                            statusClass = 'bg-amber-500 text-white'; 
                            displayStatus = currentDBStatus;
                        }
                        // --- END CORRECTED STATUS LOGIC ---
                  
                            
                        return `
                        <div class="book-card-list-item flex items-start gap-4 p-3 rounded-lg cursor-pointer" data-book-id="${bookId}">
                            <img src="${book.thumbnail || `https://placehold.co/80x120/f1f5f9/475569?text=N/A`}" alt="Cover of ${book.title}" class="w-20 h-30 object-cover rounded-md shadow-sm shrink-0">
                            <div class="flex-grow">
                                <h4 class="font-bold leading-tight">${book.title || 'N/A'}</h4>
                                <p class="text-sm text-secondary dark:text-slate-400">${authors}</p>
                                <p class="text-xs text-secondary mt-2 dark:text-slate-400">${displayIdentifier}</p>
                            </div>
                            <div class="text-right shrink-0 w-24">
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-400">Qty: ${book.quantity ?? '?'}</p>
                                <span class="inline-block mt-1 text-xs font-semibold px-2 py-1 rounded-full ${statusClass}">${displayStatus}</span>
                            </div>
                        </div>`;
                    }).join('')}
                </div>
            </div>`).join('');
    };
    
    const renderFetchedBookPreview = (book) => {
        const authors = (Array.isArray(book.authors) ? book.authors.join(', ') : book.authors) || 'Unknown Author';
        libraryPanel.innerHTML = `
            <div>
                <h3 class="preview-heading">Fetched Book Preview</h3>
                <div class="bg-slate-50 p-6 rounded-lg flex flex-col sm:flex-row gap-6 dark:bg-slate-800">
                    <img src="${book.thumbnail || `https://placehold.co/150x225/f1f5f9/475569?text=N/A`}" class="w-40 mx-auto sm:w-[150px] h-auto sm:h-[225px] object-cover rounded-lg shadow-lg shrink-0">
                    <div class="flex-grow">
                        <h2 class="text-2xl font-bold text-slate-800 dark:text-slate-100">${book.title}</h2>
                        <p class="text-lg text-slate-600 font-medium dark:text-slate-300">${authors}</p>
                        <p class="text-sm text-slate-500 mt-4 max-h-32 overflow-y-auto dark:text-slate-400">${book.description || "No description available."}</p>
                    </div>
                </div>
                <p class="text-center text-sm text-slate-400 mt-4 dark:text-slate-500">Review the details on the left, then add the book to your library.</p>
            </div>`;
    };

    // 5. UPDATED: Add accession_number to search filter
    const setupLibraryPanel = () => {
    libraryPanel.innerHTML = `
        <div class="flex flex-wrap items-end justify-between gap-6 mb-8">
            <div class="flex flex-wrap items-end gap-6">
                <div>
                    <label class="form-label">Search Library</label>
                    <div class="relative">
                        <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                        <input type="text" id="search_library" placeholder="Search by title, author, ISBN..." class="form-input input-with-icon">
                    </div>
                </div>
                <div>
                    <label class="form-label">Filter by Genre</label>
                    <form method="GET">
                        <select name="genre" class="form-input" onchange="this.form.submit()">
                            <option value="all">All Genres</option>
                            ${genresData.map(g => `<option value="${g}" ${selectedGenre === g ? 'selected' : ''}>${g}</option>`).join('')}
                        </select>
                    </form>
                </div>
            </div>
            <div>
               <a href="#" id="export-excel-btn" class="inline-flex items-center bg-green-600 text-white font-bold py-2 px-4 rounded hover:bg-green-700 transition-colors">
    <i data-lucide="file-spreadsheet" class="mr-2"></i> <span>Export to Excel</span>
</a>
            </div>
        </div>
        <div id="library-grid" class="overflow-y-auto pr-2 space-y-10" style="max-height: calc(100vh - 280px);"></div>
    `;
    
    renderGroupedLibrary(booksData);
    // Move this to the end of setupLibraryPanel
    lucide.createIcons();
    
    // The rest of your event listeners remain the same
    document.getElementById('search_library').addEventListener('input', (e) => {
        const searchTerm = e.target.value.toLowerCase();
        const filtered = booksData.filter(book => 
            book.title?.toLowerCase().includes(searchTerm) ||
            (Array.isArray(book.authors) && book.authors.some(a => a.toLowerCase().includes(searchTerm))) ||
            book.isbn?.includes(searchTerm) ||
            book.accession_number?.toLowerCase().includes(searchTerm)
        );
        renderGroupedLibrary(filtered);
    });

    libraryPanel.addEventListener('click', (e) => {
        const card = e.target.closest('.book-card-list-item');
        if (card) {
            const bookId = card.dataset.bookId;
            const book = booksData.find(b => (b._id?.$oid || b._id) === bookId);
            if (book) {
                showBookPreview(book);
            }
        }
    });
};
    
   
renderAddBookPanel();
setupLibraryPanel();


const exportBtn = document.getElementById('export-excel-btn');
const searchInput = document.getElementById('search_library');

const updateExportLink = () => {
    const urlParams = new URLSearchParams(window.location.search);
    const genre = urlParams.get('genre') || 'all';
    
    // Get the current value from the search bar
    const searchTerm = searchInput.value;

    // Build the new URL for the export script
    const exportUrl = `export_book.php?genre=${encodeURIComponent(genre)}&search=${encodeURIComponent(searchTerm)}`;
    
    // Update the button's href
    exportBtn.href = exportUrl;
};

// Update the link whenever the user types in the search bar
searchInput.addEventListener('input', updateExportLink);

// Set the correct link when the page first loads
updateExportLink();
    
});
</script>

<?php
require_once __DIR__ . '/templates/footer.php';
?>

