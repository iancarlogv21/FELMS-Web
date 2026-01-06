<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}



// 1. SET PAGE-SPECIFIC VARIABLES
$currentPage = 'students';
$pageTitle = 'Student Management - FELMS';

// --- INITIALIZE VARIABLES ---
$db_error = null;
$students = [];
$programs = [];
$years = [];
$sections = [];

$filter_program = $_GET['program'] ?? '';
$filter_year = $_GET['year'] ?? '';
$filter_section = $_GET['section'] ?? '';
$search_query = $_GET['search'] ?? '';

try {
    $dbInstance = Database::getInstance();
    $studentsCollection = $dbInstance->students();

    // --- GET UNIQUE VALUES FOR DROPDOWNS ---
    $programs = $studentsCollection->distinct('program', ['program' => ['$ne' => null, '$ne' => '']]);
    $years = $studentsCollection->distinct('year', ['year' => ['$ne' => null, '$ne' => '']]);
    $sections = $studentsCollection->distinct('section', ['section' => ['$ne' => null, '$ne' => '']]);
    sort($programs);
    sort($years);
    sort($sections);

    // --- BUILD FILTER ---
    // --- BUILD A MORE ROBUST FILTER ---
$and_conditions = []; // An array to hold all our filter conditions

// Add program and section filters if they exist
if (!empty($filter_program)) {
    $and_conditions[] = ['program' => $filter_program];
}
if (!empty($filter_section)) {
    $and_conditions[] = ['section' => $filter_section];
}

// **FIX FOR YEAR FILTER**: This now checks for the year as BOTH a number and as text.
if (!empty($filter_year)) {
    $and_conditions[] = [
        '$or' => [
            ['year' => (int)$filter_year],
            ['year' => $filter_year]
        ]
    ];
}

// Add the text search condition if it exists
if (!empty($search_query)) {
    $and_conditions[] = [
        '$or' => [
            ['first_name' => ['$regex' => $search_query, '$options' => 'i']],
            ['last_name' => ['$regex' => $search_query, '$options' => 'i']],
            ['student_no' => ['$regex' => $search_query, '$options' => 'i']],
            ['email' => ['$regex' => $search_query, '$options' => 'i']],
        ]
    ];
}

// Assemble the final filter. If any conditions were added, wrap them in an '$and'.
$filter = !empty($and_conditions) ? ['$and' => $and_conditions] : [];

    // --- FETCH AND SORT STUDENTS FOR GROUPING ---
    $sortOrder = ['program' => 1, 'year' => 1, 'section' => 1, 'last_name' => 1];
    $studentsCursor = $studentsCollection->find($filter, ['sort' => $sortOrder]);
    $students = iterator_to_array($studentsCursor);

    foreach ($students as $key => $student) {
        $studentData = (array) $student;
        $studentData['photoUrl'] = getStudentPhotoUrl($student);
        $students[$key] = $studentData;
    }

} catch (Exception $e) {
    $db_error = "MongoDB Connection Error: " . $e->getMessage();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    // Set the content type to JSON
    header('Content-Type: application/json');
    // Output the students array as a JSON string
    echo json_encode(array_values($students));
    // Stop the script so it doesn't render the rest of the HTML page
    exit;
}

// --- NEW: STYLED & GROUPED EXCEL (XLSX) EXPORT LOGIC ---
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));

    // --- Create Styles ---
    $titleStyle = [ 'font' => ['bold' => true, 'size' => 18], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER] ];
    $subtitleStyle = [ 'font' => ['italic' => true, 'size' => 10], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER] ];
    $groupHeaderStyle = [ 'font' => ['bold' => true, 'size' => 14], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E0E0']] ];
    $tableHeaderStyle = [ 'font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']] ];
    $cellBorderStyle = [ 'borders' => [ 'allBorders' => [ 'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'D9D9D9'] ] ] ];

    // --- Set Report Title & Metadata ---
    $sheet->mergeCells('A1:F1');
    $sheet->setCellValue('A1', 'Student List Report');
    $sheet->getStyle('A1')->applyFromArray($titleStyle);
    
    $sheet->mergeCells('A2:F2');
    $sheet->setCellValue('A2', 'Generated on: ' . $today->format('Y-m-d H:i:s'));
    $sheet->getStyle('A2')->applyFromArray($subtitleStyle);

    // NEW: Display active filters in the report
    $activeFilters = [];
    if (!empty($filter_program)) { $activeFilters[] = "Program: " . $filter_program; }
    if (!empty($filter_year)) { $activeFilters[] = "Year: " . $filter_year; }
    if (!empty($filter_section)) { $activeFilters[] = "Section: " . $filter_section; }
    if (!empty($search_query)) { $activeFilters[] = "Search: '" . $search_query . "'"; }
    $filterText = empty($activeFilters) ? 'None' : implode(', ', $activeFilters);
    
    $sheet->mergeCells('A3:F3');
    $sheet->setCellValue('A3', 'Active Filters: ' . $filterText);
    $sheet->getStyle('A3')->applyFromArray($subtitleStyle);

    // --- Handle case where there are no students ---
    if (empty($students)) {
        $sheet->mergeCells('A5:F5');
        $sheet->setCellValue('A5', 'No students found matching the specified criteria.');
        $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    } else {
        // --- Group students ---
        $groupedStudents = [];
        foreach ($students as $student) {
            $key = ($student['program'] ?? 'Uncategorized') . ' | Year ' . ($student['year'] ?? 'N/A') . ' - Section ' . ($student['section'] ?? 'N/A');
            $groupedStudents[$key][] = $student;
        }
        ksort($groupedStudents);

        $row = 5; // Start content from row 5
        $headers = ['Student No', 'Last Name', 'First Name', 'Middle Name', 'Email', 'Gender'];
        
        // IMPROVED: Freeze the header row so it's always visible
        $sheet->freezePane('A6');
        
        foreach ($groupedStudents as $groupName => $studentsInGroup) {
            // Add Group Header
            $sheet->mergeCells('A' . $row . ':F' . $row);
            $sheet->setCellValue('A' . $row, $groupName);
            $sheet->getStyle('A' . $row)->applyFromArray($groupHeaderStyle);
            $row++;

            // Add Table Header for the group
            $sheet->fromArray($headers, NULL, 'A' . $row);
            $sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray($tableHeaderStyle);
            $row++;

            // Add Student Data for the group
            $startRowForBorder = $row;
            foreach ($studentsInGroup as $student) {
                $sheet->setCellValue('A' . $row, $student['student_no'] ?? '');
                $sheet->setCellValue('B' . $row, $student['last_name'] ?? '');
                $sheet->setCellValue('C' . $row, $student['first_name'] ?? '');
                $sheet->setCellValue('D' . $row, $student['middle_name'] ?? '');
                $sheet->setCellValue('E' . $row, $student['email'] ?? '');
                $sheet->setCellValue('F' . $row, $student['gender'] ?? '');
                $row++;
            }
            // NEW: Apply borders to the data cells
            $sheet->getStyle('A' . $startRowForBorder . ':F' . ($row - 1))->applyFromArray($cellBorderStyle);
            $row++; // Add a blank row for spacing
        }
    }
    
    // --- Auto-size columns to be readable ---
    foreach (range('A', 'F') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }
    
    // --- Output the file to the browser ---
    $filename = 'FELMS_Student_List_' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// 2. INCLUDE THE HEADER
require_once __DIR__ . '/templates/header.php';

// 3. INCLUDE THE SIDEBAR
require_once __DIR__ . '/templates/sidebar.php';


?>




<style>
    .form-label { display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500; color: #475569; }
    .form-input { display: block; width: 100%; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.65rem 0.85rem; background-color: #fff; color: #1e293b; transition: border-color 0.2s, box-shadow 0.2s; }
    .form-input:focus { border-color: #38bdf8; box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.4); outline: none; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.65rem 1rem; border-radius: 0.5rem; font-weight: 600; transition: all 0.2s; cursor: pointer; border: none; }
    .btn-primary { background-color: #0ea5e9; color: white; }
    .btn-primary:hover { background-color: #0284c7; }
    .btn-success { background-color: #22c55e; color: white; }
    .btn-success:hover { background-color: #16a34a; }
    .btn-danger { background-color: #ef4444; color: white; }
    .btn-danger:hover { background-color: #dc2626; }
    .btn-secondary { background-color: #e2e8f0; color: #334155; }
    .btn-secondary:hover { background-color: #cbd5e1; }
    .search-input-fix { padding-left: 3rem !important; }

     [x-cloak] { display: none !important; }

    /* Styles to ensure only the card prints */
    @media print {
        body > * { display: none !important; }
        #card-modal-wrapper, #library-card-container { display: flex !important; align-items: center; justify-content: center; }
        #library-card { margin: 0; box-shadow: none; border: 1px solid #ccc; }
    }

    #student-list-container {
    transition: opacity 0.3s ease-in-out;
}

#student-list-container.is-loading {
    opacity: 0.5;
    pointer-events: none; /* Prevents clicking on old items while loading */
}
</style>

<main id="main-content" class="flex-1 p-6 md:p-10 overflow-y-auto" x-data="studentPage()">
    <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
    <div>
        <h1 class="text-4xl font-bold tracking-tight">Student Management</h1>
        <p class="text-secondary mt-2">Add, update, and organize student records.</p>
    </div>
</header>
    <div id="status-messages" class="mb-6">
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-800 p-4 mb-4 rounded-r-lg" role="alert"><p><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></p></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-4 rounded-r-lg" role="alert"><p><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></p></div>
        <?php endif; ?>
    </div>

    <div class="bg-card p-8 rounded-2xl border border-theme shadow-sm mb-8">
    <h3 id="form-title" class="text-2xl font-bold mb-6">Add New Student</h3>
    <form id="studentForm" action="student_actions.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="student_id" id="student_id">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                
                <div><label for="student_no" class="block mb-1 text-sm font-medium">Student No. <span class="text-red-500">*</span></label><input type="text" name="student_no" id="student_no" class="form-input" required></div>
                <div><label for="first_name" class="block mb-1 text-sm font-medium">First Name <span class="text-red-500">*</span></label><input type="text" name="first_name" id="first_name" class="form-input" required></div>
                <div><label for="middle_name" class="block mb-1 text-sm font-medium">Middle Name</label><input type="text" name="middle_name" id="middle_name" class="form-input"></div>
                <div><label for="last_name" class="block mb-1 text-sm font-medium">Last Name <span class="text-red-500">*</span></label><input type="text" name="last_name" id="last_name" class="form-input" required></div>
                <div><label for="email" class="block mb-1 text-sm font-medium">Email <span class="text-red-500">*</span></label><input type="email" name="email" id="email" class="form-input" required></div>
                <div><label for="gender" class="block mb-1 text-sm font-medium">Gender</label><select name="gender" id="gender" class="form-input"><option value="">Select Gender</option><option value="Male">Male</option><option value="Female">Female</option></select></div>
                <div><label for="program" class="block mb-1 text-sm font-medium">Program</label><input type="text" name="program" id="program" class="form-input" placeholder="e.g., BSTM"></div>
                <div><label for="year" class="block mb-1 text-sm font-medium">Year</label><input type="number" name="year" id="year" class="form-input" min="1" max="5"></div>
                <div><label for="section" class="block mb-1 text-sm font-medium">Section</label><input type="text" name="section" id="section" class="form-input" placeholder="e.g., D"></div>

            </div>
            <div class="flex flex-col items-center">
                <label class="block mb-1 text-sm font-medium self-start">Student Photo</label>
                <img id="photo_preview" src="https://placehold.co/150x150/e2e8f0/475569?text=Photo" alt="Student Photo" class="w-40 h-40 rounded-full object-cover mb-4 border-4 border-theme">
                <label for="image" class="btn bg-slate-200 text-slate-700 hover:bg-slate-300 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600 w-full text-center">
                    <i data-lucide="upload"></i><span>Choose File</span>
                    <input type="file" name="image" id="image" class="sr-only">
                </label>
            </div>
        </div>
       <div id="form-actions" class="mt-8 flex items-center gap-4 border-t border-theme pt-6">
    <button id="btn-save" type="submit" name="action" value="save" class="btn btn-success w-48 justify-center">
        <i data-lucide="plus-circle"></i>Save Student
    </button>
    <button id="btn-update" type="submit" name="action" value="update" class="btn btn-primary hidden w-48 justify-center">
        <i data-lucide="save"></i>Update Student
    </button>
    <button id="btn-delete" type="button" onclick="handleDeleteAction()" class="btn btn-danger hidden w-48 justify-center">
        <i data-lucide="trash-2"></i>Delete
    </button>
    <button id="btn-clear" type="button" onclick="clearForm()" class="btn bg-slate-200 text-slate-700 hover:bg-slate-300 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600 w-48 justify-center">
        <i data-lucide="x"></i>Clear
    </button>
</div>
    </form>
</div>

<div class="bg-card p-6 rounded-2xl border border-theme shadow-sm mb-8">
    <form method="GET" action="student.php" id="filter-form" class="flex flex-wrap items-end gap-4">
        
        <div class="w-80">
            <label for="search-input" class="block text-sm font-medium mb-1">Search Student</label>
            <div class="relative">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-secondary"></i>
                <input type="text" id="search-input" name="search" placeholder="Search by name, ID, or email..." 
                       class="form-input search-input-fix"
                       value="<?php echo htmlspecialchars($filter_search ?? ''); ?>">
            </div>
        </div>

        <div>
            <label for="program-filter" class="block text-sm font-medium mb-1">Program</label>
            <select name="program" id="program-filter" class="form-input">
                <option value="">All Programs</option>
                <?php foreach($programs as $p): ?>
                    <option value="<?php echo htmlspecialchars($p); ?>" <?php echo ($filter_program == $p) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <label for="year-filter" class="block text-sm font-medium mb-1">Year</label>
            <select name="year" id="year-filter" class="form-input">
                <option value="">All Years</option>
                <?php foreach($years as $y): ?>
                    <option value="<?php echo htmlspecialchars($y); ?>" <?php echo ($filter_year == $y) ? 'selected' : ''; ?>><?php echo htmlspecialchars($y); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="section-filter" class="block text-sm font-medium mb-1">Section</label>
            <select name="section" id="section-filter" class="form-input">
                <option value="">All Sections</option>
                <?php foreach($sections as $s): ?>
                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo ($filter_section == $s) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex items-center gap-2 ml-auto">
    <a href="student.php" id="reset-btn" 
   class="btn w-40 justify-center bg-slate-200 text-slate-700 hover:bg-slate-300">
    <i data-lucide="rotate-cw"></i> Reset
</a>
    <button type="button" id="export-excel-btn" class="btn btn-success w-40">
        <i data-lucide="file-spreadsheet"></i> Export
    </button>
</div>
</div>


    <div id="student-list-container" class="space-y-8">
        </div>

    <!-- === CARD MODAL === -->
    <div id="card-modal-wrapper" x-show="isModalOpen" x-cloak class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center p-4 z-50" @keydown.escape.window="closeModal()">
        <div @click.away="closeModal()" class="bg-white rounded-2xl shadow-2xl p-6 md:p-8 w-full max-w-lg mx-auto">
            <h3 class="text-2xl font-bold text-slate-800 mb-6 text-center">Library Card</h3>
            
            <template x-if="isLoading"><p class="text-slate-500 my-10 text-center">Generating card, please wait...</p></template>
            <template x-if="error"><p class="text-red-600 bg-red-100 p-4 rounded-lg my-6 text-center" x-text="error"></p></template>

            <template x-if="cardData">
                <div id="library-card-container" class="flex justify-center mb-8">
                    <!-- Improved Library Card Component -->
                    <!-- Improved Library Card Component -->
<div id="library-card" class="w-[350px] h-auto rounded-xl shadow-2xl p-4 flex flex-col gap-3 font-sans relative overflow-hidden bg-gradient-to-br from-red-600 to-red-800 text-white select-none">
    
    <!-- Decorative Circles -->
    <div class="absolute -top-4 -right-12 w-32 h-32 border-4 border-white/10 rounded-full z-0"></div>
    <div class="absolute top-16 -right-4 w-16 h-16 bg-white/5 rounded-full z-0"></div>

    <!-- Card Header -->
    <div class="flex items-center gap-3 z-10">
        <div class="bg-white/90 p-2 rounded-lg shadow-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-red-600 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
        </div>
        <div>
            <h1 class="font-bold text-base leading-tight tracking-wide">Fast & Efficient LMS</h1>
            <p class="text-sm text-red-200">Student Library Card</p>
        </div>
    </div>
    
    <!-- Student Info Section -->
    <div class="flex items-center gap-4 z-10 mt-2">
        <div class="w-20 h-20 rounded-full bg-slate-200/80 flex items-center justify-center border-4 border-white/40 shadow-lg flex-shrink-0">
             <img :src="cardData.photoUrl" alt="Student Photo" class="w-full h-full rounded-full object-cover" crossorigin="anonymous">
        </div>
        <div>
            <p class="font-bold text-xl leading-tight" x-text="cardData.fullName"></p>
            <div class="mt-1">
                <p class="text-xs font-semibold text-red-200 uppercase tracking-wider">Program</p>
                <p class="text-base font-medium" x-text="cardData.program"></p>
            </div>
        </div>
    </div>
    
    <!-- Barcode Footer -->
    <div class="bg-white rounded-lg p-2 flex items-center gap-3 z-10 shadow-inner mt-2">
        <div class="flex-grow text-center">
            <img :src="cardData.barcodeBase64" alt="Barcode" class="h-10 object-contain mx-auto" x-show="cardData.barcodeBase64">
            <p class="text-xs font-mono font-semibold text-slate-700 text-center" x-text="cardData.student_no"></p>
        </div>
        <div class="border-l border-slate-300 pl-3 pr-1 text-right self-stretch flex flex-col justify-center">
            <p class="text-[9px] font-bold text-slate-500 tracking-wider">STUDENT NO.</p>
            <p class="text-sm font-mono font-semibold text-slate-800" x-text="cardData.student_no"></p>
        </div>
    </div>
</div>


            </template>
            
            <div class="flex items-center justify-center gap-4">
                <button type="button" x-show="cardData" @click="downloadCard()" class="btn btn-success"><i data-lucide="download"></i>Download</button>
                <button type="button" @click="closeModal()" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>
</main>


<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
    // --- AlpineJS Data for Modal (Unchanged) ---
    function studentPage() {
        return {
            isModalOpen: false, isLoading: false, error: '', cardData: null,
            openCardModal(studentId) {
                this.isModalOpen = true; this.isLoading = true; this.cardData = null; this.error = '';
                fetch(`api_student_card.php?id=${studentId}`)
                    .then(res => res.ok ? res.json() : Promise.reject('Network response was not ok.'))
                    .then(data => { if (data.error) throw new Error(data.error); this.cardData = data; })
                    .catch(err => this.error = err.message).finally(() => this.isLoading = false);
            },
            closeModal() { this.isModalOpen = false; },
            downloadCard() {
                const cardElement = document.getElementById('library-card');

                html2canvas(cardElement, { 
                    scale: 3,
                    useCORS: true  // <-- ADD THIS
                }).then(canvas => {
                    const link = document.createElement('a');
                    // Added a fallback in case fullName is empty
                    const fileName = this.cardData.fullName ? this.cardData.fullName.replace(/\s+/g, '_') : 'student';
                    link.download = `library-card-${fileName}.png`;
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                }).catch(err => {
                    // <-- ADD THIS ERROR HANDLING
                    console.error('Error generating card image:', err);
                    alert('An error occurred while trying to download the card. Please check the console for details.');
                });
            }
        }
    }

    // --- Form Management Functions (Unchanged) ---
    const studentForm = document.getElementById('studentForm');
    const formTitle = document.getElementById('form-title');
    const btnSave = document.getElementById('btn-save');
    const btnUpdate = document.getElementById('btn-update');
    const btnDelete = document.getElementById('btn-delete');
    const photoPreview = document.getElementById('photo_preview');
    const defaultPhoto = 'https://placehold.co/150x150/f1f5f9/475569?text=Photo';

    function clearForm() {
        studentForm.reset(); document.getElementById('student_id').value = '';
        photoPreview.src = defaultPhoto; formTitle.textContent = 'Add New Student';
        btnSave.classList.remove('hidden'); btnUpdate.classList.add('hidden'); btnDelete.classList.add('hidden');
    }

    window.editStudent = function(student) {
        document.getElementById('student_id').value = student._id.$oid;
        document.getElementById('student_no').value = student.student_no || '';
        document.getElementById('first_name').value = student.first_name || '';
        document.getElementById('middle_name').value = student.middle_name || '';
        document.getElementById('last_name').value = student.last_name || '';
        document.getElementById('email').value = student.email || '';
        document.getElementById('gender').value = student.gender || '';
        document.getElementById('program').value = student.program || '';
        document.getElementById('year').value = student.year || '';
        document.getElementById('section').value = student.section || '';
        photoPreview.src = student.photoUrl || defaultPhoto;
        formTitle.textContent = 'Edit Student Details';
        btnSave.classList.add('hidden'); btnUpdate.classList.remove('hidden'); btnDelete.classList.remove('hidden');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    window.handleDeleteAction = function() {
        if (!confirm('Are you sure you want to delete this student? This action cannot be undone.')) {
            return;
        }

        const studentId = document.getElementById('student_id').value;
        if (!studentId) {
            alert('Cannot delete: Student ID is missing.');
            return;
        }

        // Create a temporary form to submit ONLY the ID and the action
        const tempForm = document.createElement('form');
        tempForm.method = 'POST';
        tempForm.action = 'student_actions.php';
        
        // Append the student ID
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'student_id';
        idInput.value = studentId;
        tempForm.appendChild(idInput);

        // Append the action
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete';
        tempForm.appendChild(actionInput);

        document.body.appendChild(tempForm);
        tempForm.submit();
    }

    // --- Student List Rendering Function (Unchanged) ---
    function renderGroupedStudents(studentsToRender) {
        const container = document.getElementById('student-list-container');
        if (!container) return;
        if (studentsToRender.length === 0) {
            container.innerHTML = `<div class="bg-card text-center p-16 text-secondary rounded-2xl border border-theme shadow-sm">No students found matching your criteria.</div>`;
            return;
        }
        const grouped = studentsToRender.reduce((acc, student) => {
            const key = `${student.program || 'Uncategorized'} | Year ${student.year || 'N/A'} - Section ${student.section || 'N/A'}`;
            if (!acc[key]) acc[key] = []; acc[key].push(student); return acc;
        }, {});
        let html = '';
        for (const groupName of Object.keys(grouped).sort()) {
            const students = grouped[groupName];
            html += `
                <div class="bg-card rounded-2xl border border-theme shadow-sm">
                    <h3 class="px-6 py-4 text-xl font-bold border-b border-theme">${groupName}</h3>
                    <div class="p-4 space-y-2">
                        ${students.map(student => `
                            <div class="flex items-center p-3 rounded-lg hover:bg-[var(--accent-color-light)] transition-colors">
                                <div class="flex-1 flex items-center gap-4">
                                    <img src="${student.photoUrl}" class="w-12 h-12 rounded-full object-cover">
                                    <div>
                                        <p class="font-semibold">${student.last_name || ''}, ${student.first_name || ''} ${student.middle_name || ''}</p>
                                        <p class="text-sm text-secondary font-mono">${student.student_no || 'N/A'}</p>
                                    </div>
                                </div>
                                <div class="w-32 text-secondary text-sm text-center">${student.gender || 'N/A'}</div>
                                <div class="flex-shrink-0 flex items-center gap-2">
                                    <button onclick='editStudent(${JSON.stringify(student)})' class="btn !p-2 bg-slate-200 text-slate-700 hover:bg-slate-300 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600" title="Edit Student"><i data-lucide="edit-3" class="w-4 h-4"></i></button>
                                    <button @click="openCardModal('${student._id.$oid}')" class="btn !p-2 bg-slate-200 text-slate-700 hover:bg-slate-300 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600" title="Generate Library Card"><i data-lucide="id-card" class="w-4 h-4"></i></button>
                                    <a href="student_borrow_history.php?id=${student._id.$oid}" class="btn !p-2 bg-slate-200 text-slate-700 hover:bg-slate-300 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600" title="Borrow History"><i data-lucide="history" class="w-4 h-4"></i></a>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>`;
        }
        container.innerHTML = html;
        lucide.createIcons();
    }

    // --- MAIN SCRIPT LOGIC ---
    document.addEventListener('DOMContentLoaded', () => {
        // 1. Render the initial list of students from PHP
        const initialStudentsData = <?php echo json_encode(array_values($students)); ?>;
        renderGroupedStudents(initialStudentsData);

        // =================================================================
        // NEW AND IMPROVED LIVE FILTERING LOGIC
        // =================================================================
        const filterForm = document.getElementById('filter-form');
        const searchInput = document.getElementById('search-input');
        const studentListContainer = document.getElementById('student-list-container');
        let debounceTimer;

        // The function that fetches and re-renders students
        async function applyFiltersAndRender() {
            // **IMPROVEMENT**: Add the 'is-loading' class for the smooth fade effect
            studentListContainer.classList.add('is-loading');

            const formData = new FormData(filterForm);
            const params = new URLSearchParams(formData);
            params.append('ajax', '1'); // Our special flag
            params.append('_', new Date().getTime()); // **FIX**: Prevents browser caching issues

            // For debugging: You can see what's being sent to the server
            // console.log('Filtering with:', params.toString());
            
            try {
                const response = await fetch(`student.php?${params.toString()}`);
                const students = await response.json();
                renderGroupedStudents(students);
            } catch (error) {
                console.error('Error fetching filtered students:', error);
                studentListContainer.innerHTML = `<div class="bg-card text-center p-16 text-red-600 rounded-2xl border border-theme shadow-sm">Failed to load student data.</div>`;
            } finally {
                // **IMPROVEMENT**: Always remove the loading class when done
                studentListContainer.classList.remove('is-loading');
            }
        }
        
        // Listen for changes on ALL inputs and selects inside the filter form
        filterForm.addEventListener('input', (e) => {
            // We only debounce for the text search input
            if (e.target.type === 'text') {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(applyFiltersAndRender, 350);
            } else {
                // For dropdowns, filter immediately
                applyFiltersAndRender();
            }
        });

        // Make the reset button work without a page reload
        document.getElementById('reset-btn').addEventListener('click', (e) => {
            e.preventDefault();
            filterForm.reset();
            applyFiltersAndRender();
        });

        // Handle the photo preview
        document.getElementById('image').addEventListener('change', function(event) {
            if (event.target.files && event.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) { photoPreview.src = e.target.result; };
                reader.readAsDataURL(event.target.files[0]);
            }
        });

        // Handle the excel export button (Unchanged)
        document.getElementById('export-excel-btn').addEventListener('click', function() {
            const formData = new URLSearchParams(new FormData(filterForm));
            const exportUrl = 'student.php?export=excel&' + formData.toString();
            window.location.href = exportUrl;
        });
    });
</script>

<?php
require_once __DIR__ . '/templates/footer.php';
?>
