<?php
// attendance_log.php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// Ensure error reporting is disabled for presentation, but keep logic
// ini_set('display_errors', 0);
// error_reporting(0);

$currentPage = 'attendance';
$pageTitle = 'Attendance Log - FELMS';

// Database and filter logic
$dbInstance = Database::getInstance();
$logsCollection = $dbInstance->attendance_logs();
$studentsCollection = $dbInstance->students();

$programs = $studentsCollection->distinct('program', ['program' => ['$ne' => null, '$ne' => '']]);
sort($programs);

$search_query = trim($_GET['search'] ?? '');
$filter_program = trim($_GET['program'] ?? '');
$filter_status = trim($_GET['status'] ?? '');

$pipeline = [
    ['$lookup' => ['from' => 'Students', 'localField' => 'student_no', 'foreignField' => 'student_no', 'as' => 'studentInfo']],
    ['$unwind' => ['path' => '$studentInfo', 'preserveNullAndEmptyArrays' => true]],
];
$match = [];
if (!empty($search_query)) {
    $match['$or'] = [['studentInfo.first_name' => ['$regex' => $search_query, '$options' => 'i']], ['studentInfo.last_name' => ['$regex' => $search_query, '$options' => 'i']], ['student_no' => ['$regex' => $search_query, '$options' => 'i']]];
}
if (!empty($filter_program)) { $match['studentInfo.program'] = $filter_program; }
if ($filter_status === 'in_library') { $match['time_out'] = ['$exists' => false]; } 
elseif ($filter_status === 'completed') { $match['time_out'] = ['$exists' => true]; }

if (!empty($match)) { $pipeline[] = ['$match' => $match]; }
$pipeline[] = ['$sort' => ['time_in' => -1]];
$logs = iterator_to_array($logsCollection->aggregate($pipeline));

$grouped_logs = [];
foreach ($logs as $log) {
    $program_raw = $log['studentInfo']['program'] ?? 'Uncategorized';
    $year_raw = $log['studentInfo']['year'] ?? 'N/A';
    $section_raw = $log['studentInfo']['section'] ?? 'N/A';
    
    // Grouping key (used to logically separate sections)
    $key = "$program_raw|$year_raw|$section_raw"; 
    
    // Store header details along with the logs
    if (!isset($grouped_logs[$key])) { 
        $grouped_logs[$key] = [
            'logs' => [],
            'program' => $program_raw,
            'year' => $year_raw,
            'section' => $section_raw
        ];
    }
    $grouped_logs[$key]['logs'][] = $log;
}

require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/sidebar.php';
?>
<style>
    /* Base Form and Input Styles */
    .form-label { display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500; color: #475569; }
    .form-input { display: block; width: 100%; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.65rem 0.85rem; background-color: #fff; transition: all 0.2s; }
    .form-input:focus { border-color: #ef4444; box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.4); outline: none; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.65rem 1rem; border-radius: 0.5rem; font-weight: 600; transition: all 0.2s; cursor: pointer; border: none; }
    .btn-primary { background-color: #ef4444; color: white; }
    .btn-primary:hover { background-color: #dc2626; }
    
    /* Base Secondary Button Styles */
    .btn-secondary { background-color: #e2e8f0; color: #334155; }
    .btn-secondary:hover { background-color: #cbd5e1; }
    
    /* Input padding fix */
    .search-input-padding {
        padding-left: 2.75rem !important;
    }

    /* --- CSS VARIABLE DEFINITIONS (Theme Switching) --- */
    :root {
        --card-bg: #ffffff; 
        --border-color: #e2e8f0; 
        --input-bg: #ffffff; 
        --text-color-primary: #1e293b; /* dark text */
        --text-color-secondary: #64748b; /* slate-500 for labels/icons */
        --log-card-hover: #fef2f2; 
        
        --badge-green-bg: #d1fae5;
        --badge-green-text: #065f46;
        --badge-orange-bg: #ffedd5;
        --badge-orange-text: #9a3412;

        /* Header Shadow Color (Subtle black) */
        --header-shadow-color: rgba(0, 0, 0, 0.1); 
    }

    .dark {
        --card-bg: #1e293b;
        --border-color: #475569;
        --input-bg: #334155;
        --text-color-primary: #f1f5f9; /* light text */
        --text-color-secondary: #94a3b8; /* slate-400 */
        --log-card-hover: #374151; 
        
        --badge-green-bg: #1f3b39; 
        --badge-green-text: #4ade80; 
        --badge-orange-bg: #4a3412; 
        --badge-orange-text: #fbbf24; 

        /* Header Shadow Color (Subtle white for contrast) */
        --header-shadow-color: rgba(255, 255, 255, 0.1); 
    }

    /* --- THEME-AWARE CLASS IMPLEMENTATIONS --- */

    .bg-card { 
        background-color: var(--card-bg); 
    }

    .border-theme { 
        border-color: var(--border-color); 
    }

    .text-text {
        color: var(--text-color-primary);
    }

    .text-secondary {
        color: var(--text-color-secondary);
    }
    
    /* Dark Shadow for Header */
    .header-shadow {
        box-shadow: 0 4px 6px -1px var(--header-shadow-color);
        transition: box-shadow 0.3s ease;
    }

    /* Fixed Badge Colors */
    .badge-orange-theme {
        background-color: var(--badge-orange-bg) !important;
        color: var(--badge-orange-text) !important;
    }
    .badge-green-theme {
        background-color: var(--badge-green-bg) !important;
        color: var(--badge-green-text) !important;
    }
    
    /* Secondary Button Fix */
    .btn-secondary { 
        background-color: #e2e8f0; 
        color: #334155; 
        border-color: #e2e8f0;
    }
    .dark .btn-secondary {
        background-color: #334155; 
        color: #f1f5f9;
        border-color: #475569;
    }

    /* --- THEME-AWARE INPUT STYLES (NEW) --- */
.form-input-theme {
    background-color: var(--input-bg);
    color: var(--text-color-primary); /* Ensures text entered/selected is visible */
    border-color: var(--border-color);
}
.form-input-theme option {
    background-color: var(--card-bg); /* Ensures dropdown options match theme */
    color: var(--text-color-primary);
}
</style>

<main id="main-content" class="flex-1 p-6 md:p-10 overflow-y-auto pb-10">
    <header class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-8">
    <div>
        <h1 class="text-4xl font-bold tracking-tight text-text">Attendance Log</h1>
        <p class="text-secondary mt-2">Review, filter, and manage student attendance records.</p>
    </div>
    <a href="export_attendance.php?<?= http_build_query($_GET) ?>" class="btn bg-green-600 hover:bg-green-700 text-white">
    <i data-lucide="file-spreadsheet"></i>Export Excel
</a>
</header>

    <div id="status-message-container" class="my-6"></div>

<div class="bg-card p-6 rounded-xl border border-theme shadow-sm my-8">
    <form method="GET" class="grid grid-cols-1 lg:grid-cols-10 gap-4">
        <div class="lg:col-span-4">
            <label for="search" class="form-label text-secondary">Search by Name/ID</label>
            <div class="relative">
                <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-secondary"></i>
                <input type="text" name="search" id="search" placeholder="Student Name or ID..." 
                        class="form-input form-input-theme search-input-padding" 
                        value="<?= htmlspecialchars($search_query) ?>">
            </div>
        </div>
        <div class="lg:col-span-2">
            <label for="program-filter" class="form-label text-secondary">Program</label>
            <select name="program" class="form-input form-input-theme">
                <option value="">All Programs</option>
                <?php foreach($programs as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= ($filter_program == $p) ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="lg:col-span-2">
            <label for="status-filter" class="form-label text-secondary">Status</label>
            <select name="status" class="form-input form-input-theme">
                <option value="">All</option>
                <option value="in_library" <?= ($filter_status == 'in_library') ? 'selected' : '' ?>>In Library</option>
                <option value="completed" <?= ($filter_status == 'completed') ? 'selected' : '' ?>>Completed</option>
            </select>
        </div>
        <div class="lg:col-span-2 flex items-end gap-2">
            <button type="submit" class="btn btn-primary w-full"><i data-lucide="filter"></i>Filter</button>
            <a href="attendance_log.php" class="btn btn-secondary w-full"><i data-lucide="rotate-cw"></i>Reset</a>
        </div>
    </form>
</div>

    <div class="max-h-[70vh] overflow-y-auto pr-2">
    <div class="space-y-6">
        <?php if (empty($grouped_logs)): ?>
            <div class="text-center p-16 text-secondary bg-card rounded-2xl border border-theme shadow-sm">No records found.</div>
        <?php else: ?>
            <?php foreach ($grouped_logs as $key => $group): ?>
                
                <div class="bg-card rounded-xl border border-theme header-shadow p-4 shadow-sm space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between border-b border-theme/50 pb-2 mb-2">
                        <p class="text-lg font-bold">
                            <span class="text-text">Program:</span> 
                            <span class="text-red-600"><?= htmlspecialchars($group['program']) ?></span>
                        </p>
                        <p class="text-sm font-medium text-text mt-1 sm:mt-0">
                            <span class="text-text">Year:</span> 
                            <span class="font-bold text-red-600"><?= htmlspecialchars($group['year']) ?></span> 
                            <span class="text-text">| Section:</span> 
                            <span class="font-bold text-red-600"><?= htmlspecialchars($group['section']) ?></span>
                        </p>
                    </div>
                    
                    <div class="space-y-3">
                        <?php foreach($group['logs'] as $log): ?>
                            <?php
                                $timeInManila = $log['time_in']->toDateTime()->setTimezone(new DateTimeZone('Asia/Manila'));
                                
                                $mi = $log['studentInfo']['middle_initial'] ?? '';
                                $studentNameDisplay = htmlspecialchars($log['studentInfo']['last_name'] . ', ' . $log['studentInfo']['first_name'] . ' ' . (empty($mi) ? '' : $mi));

                                $locationDisplay = htmlspecialchars($log['location'] ?? 'N/A'); 

                                if (isset($log['time_out'])) {
                                    $timeOutManila = $log['time_out']->toDateTime()->setTimezone(new DateTimeZone('Asia/Manila'));
                                    $diff = $timeOutManila->getTimestamp() - $timeInManila->getTimestamp();
                                    $durationHtml = '<span class="text-sm font-semibold px-2.5 py-1 rounded-full badge-green-theme">' . gmdate('H\h i\m s\s', $diff) . '</span>';
                                    $timeOutDisplay = '<p class="font-medium text-text text-sm">' . $timeOutManila->format('h:i A') . '</p>';
                                } else {
                                    $durationHtml = '<span class="text-sm font-semibold text-secondary">N/A</span>';
                                    $timeOutDisplay = '<span class="text-xs font-bold px-2.5 py-1 rounded-full badge-orange-theme">In Library</span>';
                                }
                            ?>
                            
                            <div id="log-<?= (string)$log['_id'] ?>" class="log-item flex justify-between items-center py-2 px-3 border-b border-theme/50 last:border-b-0">
                                
                                <div class="flex items-center gap-4 w-1/3 min-w-48">
                                    <img src="<?= getStudentPhotoUrl($log['studentInfo']) ?>" class="w-10 h-10 rounded-full object-cover">
                                    <div>
                                        <p class="font-semibold text-base text-text leading-tight"><?= $studentNameDisplay ?></p>
                                        <p class="text-sm text-secondary font-mono leading-none"><?= htmlspecialchars($log['student_no']) ?></p>
                                    </div>
                                </div>
                                
                                <div class="hidden sm:grid sm:grid-cols-5 w-2/3 items-center text-sm">
                                    
                                    <div class="hidden lg:block">
                                        <p class="text-xs uppercase text-secondary font-semibold">Date</p>
                                        <p class="font-medium text-text"><?= $timeInManila->format('M d, Y') ?></p>
                                    </div>

                                    <div>
                                        <p class="text-xs uppercase text-secondary font-semibold">Time In</p>
                                        <p class="font-medium text-text"><?= $timeInManila->format('h:i A') ?></p>
                                    </div>
                                    
                                    <div>
                                        <p class="text-xs uppercase text-secondary font-semibold">Time Out</p>
                                        <?= $timeOutDisplay ?>
                                    </div>
                                    
                                    <div>
                                        <p class="text-xs uppercase text-secondary font-semibold">Duration</p>
                                        <?= $durationHtml ?>
                                    </div>
                                    
                                    <div class="text-right">
                                        <p class="font-medium text-text"><?= $locationDisplay ?></p>
                                    </div>
                                </div>
                                
                                <div class="ml-auto">
                                    <button onclick="deleteAttendance('<?= (string)$log['_id'] ?>')" class="p-2 text-secondary rounded-md hover:text-red-600 transition-colors">
                                        <i data-lucide="trash-2" class="w-5 h-5"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</main>
<script>
    async function deleteAttendance(logId) {
        if (!confirm('Are you sure you want to delete this record? This action cannot be undone.')) { return; }
        const formData = new FormData();
        formData.append('log_id', logId);
        try {
            const response = await fetch('api_delete_attendance.php', { method: 'POST', body: formData });
            const result = await response.json();
            const statusDiv = document.getElementById('status-message-container');
            const alertType = result.status === 'success' ? 'green' : 'red';
            statusDiv.innerHTML = `<div class="bg-${alertType}-100 border-l-4 border-${alertType}-500 text-${alertType}-800 p-4 rounded-r-lg" role="alert"><p>${result.message || 'An error occurred.'}</p></div>`;
            if (result.status === 'success') {
                const elementToRemove = document.getElementById(`log-${logId}`);
                if (elementToRemove) {
                    // Find the parent group to check if it becomes empty
                    const groupContainer = elementToRemove.closest('.space-y-3');
                    
                    elementToRemove.style.transition = 'opacity 0.3s ease-out';
                    elementToRemove.style.opacity = '0';
                    setTimeout(() => {
                        elementToRemove.remove();
                        
                        // Check if the parent group is now empty and remove the whole card
                        if (groupContainer && groupContainer.children.length === 0) {
                            groupContainer.closest('.bg-card').remove();
                        }
                    }, 300);
                }
            }
        } catch (error) {
            console.error('Deletion failed:', error);
            document.getElementById('status-message-container').innerHTML = `<div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 rounded-r-lg" role="alert"><p>A network error occurred.</p></div>`;
        }
    }
</script>
<?php
require_once __DIR__ . '/templates/footer.php';
?>