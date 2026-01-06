<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php'; // Assuming getStudentPhotoUrl() is defined here

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
        // Use a placeholder if getStudentPhotoUrl is not yet defined
        $studentData['photoUrl'] = getStudentPhotoUrl($student ?? null); 
        // Ensure _id is available as a string for JS
        $studentData['_id'] = (string)($student['_id'] ?? null);
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
    /* 1. Base Utility Styles */
    /* Light Mode */
    .form-label { display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500; color: #475569; }
    .form-input { 
        display: block; width: 100%; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.65rem 0.85rem; 
        background-color: #fff; color: #1e293b; 
        transition: border-color 0.2s, box-shadow 0.2s; 
    }
    .form-input:focus { border-color: #f97316; /* Orange Focus */ box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.4); outline: none; }
    .search-input-fix { padding-left: 3rem !important; }

    /* Dark Mode Overrides for Inputs (Explicitly using Tailwind-style colors) */
    html.dark .form-input {
        background-color: #1f2937 !important; /* slate-800 or similar */
        border-color: #475569 !important; /* slate-600 */
        color: #f1f5f9 !important; /* slate-100 */
    }
    html.dark .form-input:focus {
        border-color: #f97316 !important; /* Orange Focus */
        box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.4) !important;
    }

    /* 2. Button Styling (Orange Theme) */
    .btn { 
        display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; 
        padding: 0.75rem 1.25rem;
        border-radius: 0.5rem;
        font-weight: 600; transition: all 0.2s; cursor: pointer; border: none; 
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        line-height: 1;
    }
    /* Primary button is Orange */
    .btn-primary { background-color: #f97316; color: white; }
    .btn-primary:hover { background-color: #ea580c; }
    /* Success is Green */
    .btn-success { background-color: #22c55e; color: white; }
    .btn-success:hover { background-color: #16a34a; }
    /* Secondary/Neutral buttons */
    .btn-secondary { background-color: #e2e8f0; color: #334155; }
    .btn-secondary:hover { background-color: #cbd5e1; }

    /* Dark Mode Overrides for Secondary/Neutral Buttons */
    html.dark .btn-secondary,
    html.dark .btn.bg-slate-200 { 
        background-color: #374151 !important; /* slate-700 dark theme */
        color: #e2e8f0 !important; /* slate-200 light text */
    }
    html.dark .btn-secondary:hover,
    html.dark .btn.bg-slate-200:hover { 
        background-color: #4b5563 !important; /* slate-600 hover */
    }


    /* 3. Student List Custom Styles (Light Mode Fixes) */
    /* NEW: General Group Card */
    .student-group-card {
        background-color: var(--card-bg-color); /* Theme-aware background */
    }

    /* NEW: Group Header */
    .student-group-header {
        background-color: #f1f5f9; /* Light slate for light mode */
        border-bottom-color: #e2e8f0; /* Light border */
        color: #334155; /* Slate-700 for text */
    }
    html.dark .student-group-header {
        background-color: #1e293b; /* slate-800 for dark mode */
        border-bottom-color: #334155; /* slate-700 border */
        color: #94a3b8; /* slate-400 for text */
    }

    /* NEW: Student Item Text Color */
    .student-item-detail {
        color: #0f172a; /* Light mode text-primary */
    }
    html.dark .student-item-detail {
        color: #f1f5f9; /* Dark mode text-primary */
    }
    .student-item-detail-secondary {
        color: #64748b; /* Light mode text-secondary */
    }
    html.dark .student-item-detail-secondary {
        color: #94a3b8; /* Dark mode text-secondary */
    }


    .student-item {
        transition: background-color 0.15s ease-in-out;
        border-radius: 0.5rem;
        cursor: pointer;
    }
    /* Light Mode Hover */
    .student-item:hover {
        background-color: #f1f5f9; /* slate-100 hover for light mode */
    }
    /* Dark Mode Hover */
    html.dark .student-item:hover {
        background-color: #334155; /* slate-700 hover for dark mode */
    }
    
    /* Specific styles for list action buttons (Orange Theme) */
    .btn-action-view {
        background-color: #ffedd5; /* Light Orange */
        color: #f97316; /* Dark Orange */
        box-shadow: none;
        padding: 0.5rem !important; 
    }
    .btn-action-view:hover {
        background-color: #fed7aa; /* Slightly darker orange hover */
    }
    .btn-action-edit {
        background-color: #e2e8f0; /* Light Gray */
        color: #334155; /* Dark Gray */
        box-shadow: none;
        padding: 0.5rem !important; 
    }
    .btn-action-edit:hover {
        background-color: #cbd5e1; /* Slightly darker gray hover */
    }
    
    /* NEW: Delete Button Styling (Red theme) */
    .btn-action-delete {
        background-color: #fee2e2; /* Red-100 Light Mode */
        color: #ef4444; /* Red-500 Light Mode */
        box-shadow: none;
        padding: 0.5rem !important; 
    }
    .btn-action-delete:hover {
        background-color: #fecaca; /* Red-200 Hover */
    }


    /* Dark Mode Overrides for Action Buttons */
    html.dark .btn-action-view {
        background-color: #7c2d12 !important; /* orange-900 Dark Mode */
        color: #fdba74 !important; /* orange-300 Light Text */
    }
    html.dark .btn-action-view:hover {
        background-color: #9a3412 !important; /* orange-800 Hover */
    }
    html.dark .btn-action-edit {
        background-color: #475569 !important; /* Slate-600 Dark Mode */
        color: #f1f5f9 !important; /* Slate-100 Light Text */
    }
    html.dark .btn-action-edit:hover {
        background-color: #334155 !important; /* Slate-700 Hover */
    }
    html.dark .btn-action-delete {
        background-color: #7f1d1d !important; /* Red-900 Dark Mode */
        color: #f87171 !important; /* Red-400 Light Text */
    }
    html.dark .btn-action-delete:hover {
        background-color: #991b1b !important; /* Red-800 Hover */
    }


    /* General Alpine/Loading Styles */
      [x-cloak] { display: none !important; }
    #student-list-container {
        transition: opacity 0.3s ease-in-out;
    }
    #student-list-container.is-loading {
        opacity: 0.5;
        pointer-events: none;
    }
</style>

<main id="main-content" class="flex-1 p-6 md:p-10 overflow-y-auto" x-data="studentPage()">
    <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
        <div>
            <h1 class="text-4xl font-bold tracking-tight">Student Management</h1>
            <p class="text-secondary mt-2">View and manage all student records.</p>
        </div>
        <a href="student_edit.php" class="btn btn-primary justify-center mt-4 md:mt-0 shadow-lg hover:shadow-xl transition-shadow">
            <i data-lucide="user-plus"></i> Add New Student
        </a>
    </header>
    <div id="status-messages" class="mb-6">
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-800 p-4 mb-4 rounded-r-lg" role="alert"><p><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></p></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-4 rounded-r-lg" role="alert"><p><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></p></div>
        <?php endif; ?>
    </div>

    <div class="bg-card p-6 rounded-2xl border border-theme shadow-sm mb-8">
        <form method="GET" action="student.php" id="filter-form" class="flex flex-wrap items-end gap-4">
            
            <div class="w-80">
                <label for="search-input" class="block text-sm font-medium mb-1 dark:text-slate-400">Search Student</label>
                <div class="relative">
                    <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-secondary dark:text-slate-400"></i>
                    <input type="text" id="search-input" name="search" placeholder="Search by name, ID, or email..." 
                        class="form-input search-input-fix"
                        value="<?php echo htmlspecialchars($filter_search ?? ''); ?>">
                </div>
            </div>

            <div>
                <label for="program-filter" class="block text-sm font-medium mb-1 dark:text-slate-400">Program</label>
                <select name="program" id="program-filter" class="form-input">
                    <option value="">All Programs</option>
                    <?php foreach($programs as $p): ?>
                        <option value="<?php echo htmlspecialchars($p); ?>" <?php echo ($filter_program == $p) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="year-filter" class="block text-sm font-medium mb-1 dark:text-slate-400">Year</label>
                <select name="year" id="year-filter" class="form-input">
                    <option value="">All Years</option>
                    <?php foreach($years as $y): ?>
                        <option value="<?php echo htmlspecialchars($y); ?>" <?php echo ($filter_year == $y) ? 'selected' : ''; ?>><?php echo htmlspecialchars($y); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="section-filter" class="block text-sm font-medium mb-1 dark:text-slate-500">Section</label>
                <select name="section" id="section-filter" class="form-input">
                    <option value="">All Sections</option>
                    <?php foreach($sections as $s): ?>
                        <option value="<?php echo htmlspecialchars($s); ?>" <?php echo ($filter_section == $s) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center gap-2 ml-auto">
                <a href="student.php" id="reset-btn" 
                class="btn justify-center bg-slate-200 text-slate-700 hover:bg-slate-300">
                <i data-lucide="rotate-cw"></i> Reset
            </a>
                <button type="button" id="export-excel-btn" class="btn btn-success justify-center">
                    <i data-lucide="file-spreadsheet"></i> Export
                </button>
            </div>
        </form>
    </div>


    <div id="student-list-container" class="space-y-6">
    </div>

</main>


<script>
    // --- AlpineJS Data (Simplified) ---
    function studentPage() {
        return {}
    }

    // Function to handle the actual delete action: Creates a form and submits to student_actions.php
    function deleteStudent(studentId) {
        if (!confirm('Are you sure you want to permanently delete this student record? This action cannot be undone.')) {
            return;
        }

        // 1. Create a dynamic form
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'student_actions.php'; // Points to your existing action script
        form.style.display = 'none';

        // 2. Add action field (required by student_actions.php switch/case)
        const actionField = document.createElement('input');
        actionField.type = 'hidden';
        actionField.name = 'action';
        actionField.value = 'delete';
        form.appendChild(actionField);

        // 3. Add student_id field (required by handleDelete function)
        const idField = document.createElement('input');
        idField.type = 'hidden';
        idField.name = 'student_id';
        idField.value = studentId;
        form.appendChild(idField);

        // 4. Append and submit (This triggers the page reload)
        document.body.appendChild(form);
        form.submit();
    }


    // --- Student List Rendering Function ---
    function renderGroupedStudents(studentsToRender) {
        const container = document.getElementById('student-list-container');
        if (!container) return;
        if (studentsToRender.length === 0) {
            container.innerHTML = `<div class="bg-card text-center p-16 text-secondary rounded-2xl border border-theme shadow-sm">No students found matching your criteria.</div>`;
            return;
        }
        const grouped = studentsToRender.reduce((acc, student) => {
            // Grouping key creation: Use clean separators for easy parsing
            const key = `${student.program || 'Uncategorized'} | ${student.year || 'N/A'} | ${student.section || 'N/A'}`;
            if (!acc[key]) acc[key] = []; acc[key].push(student); return acc;
        }, {});
        let html = '';
        
        for (const groupName of Object.keys(grouped).sort()) {
            const students = grouped[groupName];
            
            // FIX: Correctly parse the groupName string created above
            const parts = groupName.split(' | ');
            const program = parts[0];
            const year = parts[1];
            const section = parts[2];

            html += `
                <div class="student-group-card rounded-xl border border-theme shadow-md overflow-hidden">
                    <div class="px-6 py-3 student-group-header border-b border-theme flex flex-wrap items-center gap-6">
                        <span class="text-sm font-semibold">Program: <strong class="text-lg text-[var(--accent-color)]">${program}</strong></span>
                        <span class="text-sm font-semibold">Year: <strong class="text-lg student-item-detail">${year}</strong></span>
                        <span class="text-sm font-semibold">Section: <strong class="text-lg student-item-detail">${section}</strong></span>
                    </div>
                    
                    <div class="p-4 space-y-2">
                        ${students.map(student => `
                            <div id="student-item-${student._id}" class="student-item flex items-center p-3 rounded-lg transition-colors">
                                <div class="flex-1 flex items-center gap-4">
                                    <img src="${student.photoUrl}" class="w-12 h-12 rounded-full object-cover border border-theme shadow-sm">
                                    <div>
                                        <p class="font-semibold student-item-detail">${student.last_name || ''}, ${student.first_name || ''} ${student.middle_name || ''}</p>
                                        <p class="text-sm font-mono student-item-detail-secondary">${student.student_no || 'N/A'}</p>
                                    </div>
                                </div>
                                <div class="w-32 text-sm font-medium text-center student-item-detail-secondary">${student.gender || 'N/A'}</div>
                                <div class="flex-shrink-0 flex items-center gap-2">
                                    <a href="student_view.php?id=${student._id}" class="btn !p-2 btn-action-view shadow-none" title="View Profile"><i data-lucide="user" class="w-4 h-4"></i></a>
                                    <a href="student_edit.php?id=${student._id}" class="btn !p-2 btn-action-edit shadow-none" title="Edit Student"><i data-lucide="edit-3" class="w-4 h-4"></i></a>
                                    <button onclick="deleteStudent('${student._id}')" class="btn !p-2 btn-action-delete shadow-none" title="Delete Student"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
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
        // LIVE FILTERING LOGIC 
        // =================================================================
        const filterForm = document.getElementById('filter-form');
        const studentListContainer = document.getElementById('student-list-container');
        let debounceTimer;

        // The function that fetches and re-renders students
        async function applyFiltersAndRender() {
            studentListContainer.classList.add('is-loading');

            const formData = new FormData(filterForm);
            const params = new URLSearchParams(formData);
            params.append('ajax', '1'); // Our special flag
            params.append('_', new Date().getTime()); // Prevents browser caching issues
            
            try {
                const response = await fetch(`student.php?${params.toString()}`);
                const students = await response.json();
                renderGroupedStudents(students);
            } catch (error) {
                console.error('Error fetching filtered students:', error);
                studentListContainer.innerHTML = `<div class="bg-card text-center p-16 text-red-600 rounded-2xl border border-theme shadow-sm">Failed to load student data.</div>`;
            } finally {
                studentListContainer.classList.remove('is-loading');
            }
        }
        
        // Listen for changes on ALL inputs and selects inside the filter form
        filterForm.addEventListener('input', (e) => {
            if (e.target.type === 'text') {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(applyFiltersAndRender, 350);
            } else {
                applyFiltersAndRender();
            }
        });

        // Make the reset button work without a page reload
        document.getElementById('reset-btn').addEventListener('click', (e) => {
            e.preventDefault();
            filterForm.reset();
            applyFiltersAndRender();
        });

        // FIX: Export Button Functionality 
        document.getElementById('export-excel-btn').addEventListener('click', function() {
            const formData = new URLSearchParams(new FormData(filterForm));
            // Ensure all current filters are included in the export URL
            const exportUrl = 'student.php?export=excel&' + formData.toString();
            window.location.href = exportUrl;
        });
    });
</script>

<?php
require_once __DIR__ . '/templates/footer.php';
?>