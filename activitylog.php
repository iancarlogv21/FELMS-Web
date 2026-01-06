<?php
session_start();
// --- Dependencies ---
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// 1. SET PAGE-SPECIFIC VARIABLES
$currentPage = 'activitylog';
$pageTitle = 'Activity Log - FELMS';

// --- PHP LOGIC to fetch real activity logs ---
$logs = [];
$error = null;
$limit = 100; // Get the last N activities
$uniqueActions = []; // To populate the filter dropdown

try {
    $db = Database::getInstance();
    $logsCollection = $db->activity_logs();
    $options = ['sort' => ['timestamp' => -1], 'limit' => $limit];

    // Fetch the logs
    $cursor = $logsCollection->find([], $options);
    $logs = iterator_to_array($cursor);

    // Get unique actions for the filter dropdown from the fetched logs
    $actionsSet = [];
    foreach ($logs as $log) {
        if (isset($log['action'])) {
            $actionsSet[] = $log['action'];
        }
    }
    // Remove duplicates and sort them
    $uniqueActions = array_unique($actionsSet);
    sort($uniqueActions);

} catch (Exception $e) {
    $error = "Could not fetch activity logs: " . $e->getMessage();
}

// 2. INCLUDE THE HEADER
require_once __DIR__ . '/templates/header.php';

// 3. INCLUDE THE SIDEBAR
require_once __DIR__ . '/templates/sidebar.php';
?>

<style>
/* --- NEW BADGE COLOR DEFINITIONS (Consistent Solid Colors) --- */
.badge-green { /* CREATE/ADD */
    background-color: #10B981; /* Emerald-500 */
    color: white !important;
}
.badge-yellow { /* UPDATE */
    background-color: #FBBF24; /* Amber-400 */
    color: #1e293b !important; /* Dark Slate Text */
}
.badge-red { /* DELETE */
    background-color: #EF4444; /* Red-500 */
    color: white !important;
}
.badge-blue { /* BORROW */
    background-color: #3B82F6; /* Blue-500 */
    color: white !important;
}
.badge-indigo { /* RETURN */
    background-color: #6366F1; /* Indigo-500 */
    color: white !important;
}
.badge-default { /* Default/OTHER */
    background-color: #94A3B8; /* Slate-400 */
    color: #1e293b !important; /* Dark Slate Text */
}

/* --- EXISTING UI STYLES --- */
/* These custom CSS rules ensure the Action column is wide enough to prevent wrapping */
.action-header {
    min-width: 170px; /* Increased minimum width */
}
.action-cell {
    min-width: 170px;
}

/* CUSTOM SCROLLBAR STYLES */
/* ... (Your existing scrollbar styles omitted for brevity) ... */

/* Sticky table header backgrounds */
#activityLogTable thead th {
    background-color: var(--color-body, #f8f9fa);
}
.dark #activityLogTable thead th {
    background-color: var(--color-body-dark, #1e293b);
}
</style>

<main id="main-content" class="flex-1 p-6 lg:p-8">
    
    <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10">
        <div>
            <h1 class="text-4xl font-bold tracking-tight text-primary">System Activity Log</h1> 
            <p class="text-secondary mt-2">A record of the last <?= $limit ?> major actions performed in the system.</p>
        </div>
    </header>

    <?php if (isset($error)): ?>
        <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-6"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <div class="flex flex-col sm:flex-row gap-4 mb-6 items-center justify-between">
        <div class="relative w-full sm:w-80">
            <input type="text" id="logSearch" placeholder="Search by user or details..." 
                   class="w-full pl-10 pr-4 py-2 border border-theme rounded-lg focus:ring-2 focus:ring-primary-dark focus:border-primary bg-card text-primary placeholder-secondary" 
                   onkeyup="filterLogs()">
            <svg class="w-4 h-4 text-secondary absolute left-3 top-1/2 transform -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
        </div>

        <div class="flex gap-4 w-full sm:w-auto">
            <select id="actionFilter" onchange="filterLogs()" class="px-4 py-2 border border-theme rounded-lg focus:ring-2 focus:ring-primary-dark focus:border-primary bg-card text-primary">
                <option value="">All Actions</option>
                <?php foreach ($uniqueActions as $action): ?>
                    <option value="<?= htmlspecialchars($action) ?>"><?= htmlspecialchars(str_replace('_', ' ', $action)) ?></option>
                <?php endforeach; ?>
            </select>

            <a href="#" onclick="exportLogsToXLSX(event)" 
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-green-600 rounded-lg shadow-sm hover:bg-green-700 hover:shadow-md hover:-translate-y-px transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                <i data-lucide="file-spreadsheet" class="w-4 h-4"></i>
                <span>Export to Excel</span>
            </a>
        </div>
    </div>
    <div class="bg-card p-6 rounded-2xl shadow-md border border-theme">
        <div class="overflow-y-auto max-h-[70vh] custom-scrollbar">
            <table class="w-full text-sm text-left" id="activityLogTable">
                <thead class="text-xs text-secondary uppercase bg-body sticky top-0 z-10 border-b border-theme">
                    <tr class="border-b border-theme">
                        <th scope="col" class="px-6 py-3">Timestamp</th>
                        <th scope="col" class="px-6 py-3">User</th>
                        <th scope="col" class="px-6 py-3 action-header">Action</th>
                        <th scope="col" class="px-6 py-3">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-theme" id="logTableBody">
                    <?php if (empty($logs)): ?>
                        <tr id="noLogsRow"><td colspan="4" class="text-center py-16 text-secondary">No activities have been logged yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr class="hover:bg-body" data-action="<?= htmlspecialchars($log['action'] ?? '') ?>">
                            <td class="px-6 py-4 whitespace-nowrap text-secondary">
                                <?php 
                                    $timeFormatted = 'Invalid Date';
                                    if (isset($log['timestamp'])) {
                                        try {
                                            // Assuming $log['timestamp'] is a MongoDB BSON UTCDateTime object
                                            $timestamp = $log['timestamp']->toDateTime()->setTimezone(new DateTimeZone('Asia/Manila'));
                                            $timeFormatted = $timestamp->format('M d, Y, h:i A');
                                        } catch (Exception $e) {
                                            // Fallback if datetime conversion fails
                                        }
                                    }
                                    echo $timeFormatted;
                                ?>
                            </td>
                            <td class="px-6 py-4 font-medium text-primary log-user"><?= htmlspecialchars($log['username'] ?? 'N/A') ?></td>
                           <td class="px-6 py-4 log-action action-cell">
                                    <?php 
                                        $action = $log['action'] ?? '';
                                        // Logic to handle old and new constants with a friendly name
                                        $displayName = str_replace('_', ' ', $action);
                                        if ($action === 'RETURN_RECORD_DELETE' || $action === 'RETURN_DELETE') {
                                            $displayName = 'RETURN RECORD DELETE';
                                        }
                                        
                                        // Determine CSS class based on action type (Simplified)
                                        $class = 'badge-default'; 
                                        if (strpos($action, 'CREATE') !== false || strpos($action, 'ADD') !== false) {
                                            $class = 'badge-green';
                                        } elseif (strpos($action, 'UPDATE') !== false) {
                                            $class = 'badge-yellow';
                                        } elseif (strpos($action, 'DELETE') !== false) {
                                            $class = 'badge-red';
                                        } elseif (strpos($action, 'BORROW') !== false) {
                                            $class = 'badge-blue';
                                        } elseif (strpos($action, 'RETURN') !== false) {
                                            $class = 'badge-indigo';
                                        }
                                    ?>
                                    <span class="px-2 py-1 font-semibold leading-tight text-xs rounded-full whitespace-nowrap <?= $class ?>">
                                        <?= htmlspecialchars($displayName) ?>
                                    </span>
                                </td>
                            <td class="px-6 py-4 text-secondary log-details"><?= htmlspecialchars($log['details'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <tr id="noResultsRow" class="hidden"><td colspan="4" class="text-center py-16 text-secondary">No activities matched your search or filter criteria.</td></tr>
        </div>
    </div>
</main>

<style>
    /* These custom CSS rules ensure the Action column is wide enough to prevent wrapping */
    .action-header {
        min-width: 170px; /* Increased minimum width */
    }
    .action-cell {
        min-width: 170px;
    }
    
    /* CUSTOM SCROLLBAR STYLES */
    .custom-scrollbar::-webkit-scrollbar {
        width: 8px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: #cbd5e1; /* slate-300 */
        border-radius: 4px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background-color: #f1f5f9; /* slate-100 */
    }
    /* Dark mode adjustments */
    .dark .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: #475569; /* slate-600 */
    }
    .dark .custom-scrollbar::-webkit-scrollbar-track {
        background-color: #1e293b; /* slate-800 */
    }

    /* Sticky table header backgrounds */
    #activityLogTable thead th {
        background-color: var(--color-body, #f8f9fa);
    }
    .dark #activityLogTable thead th {
        background-color: var(--color-body-dark, #1e293b);
    }
</style>

<script>
    /**
     * Client-Side Filtering for Activity Log Table (Search Bar and Dropdown)
     */
    function filterLogs() {
        const searchText = document.getElementById('logSearch').value.toLowerCase();
        const selectedAction = document.getElementById('actionFilter').value;
        const rows = document.getElementById('logTableBody').getElementsByTagName('tr');
        let visibleRowCount = 0;

        // Hide the static "No logs yet" message if it exists
        const noLogsRow = document.getElementById('noLogsRow');
        if (noLogsRow) noLogsRow.style.display = 'none';

        // Get the "No results found" row for dynamic message
        const noResultsRow = document.getElementById('noResultsRow');

        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            
            // Skip the "No logs yet" row if it was the only one
            if (row.id === 'noLogsRow') continue;

            const user = row.querySelector('.log-user')?.textContent.toLowerCase() || '';
            const actionElement = row.querySelector('.log-action')?.textContent.toLowerCase() || '';
            const details = row.querySelector('.log-details')?.textContent.toLowerCase() || '';
            const rowAction = row.getAttribute('data-action') || '';
            
            let searchMatch = false;
            if (searchText === '') {
                searchMatch = true;
            } else {
                // Check for search text in user, action label, or details
                if (user.includes(searchText) || details.includes(searchText) || actionElement.includes(searchText)) {
                    searchMatch = true;
                }
            }

            // Check for action filter match
            const actionMatch = selectedAction === '' || rowAction === selectedAction;

            // Show or hide the row
            if (searchMatch && actionMatch) {
                row.style.display = '';
                visibleRowCount++;
            } else {
                row.style.display = 'none';
            }
        }

        // Show "No results found" message if no rows are visible
        if (visibleRowCount === 0) {
            noResultsRow.style.display = '';
        } else {
            noResultsRow.style.display = 'none';
        }
    }

    /**
     * Triggers the export to XLSX/CSV by navigating to the export script.
     * @param {Event} event The click event to prevent default link action.
     */
    function exportLogsToXLSX(event) {
        // Prevents the default action of the <a> tag
        if (event) {
            event.preventDefault();
        }
        
        // Triggers the download by navigating to the dedicated export script
        window.location.href = 'export_logs.php';
    }

    // FIX: Add auto-refresh to ensure data is periodically pulled from MongoDB.
    // This solves the problem of manual deletions not showing up until a manual refresh.
    document.addEventListener('DOMContentLoaded', () => {
        const refreshTimeSeconds = 30; // Refresh every 30 seconds
        console.log(`Setting up auto-refresh: page will refresh every ${refreshTimeSeconds} seconds to ensure logs are up-to-date.`);

        setTimeout(() => {
            window.location.reload();
        }, refreshTimeSeconds * 1000);
    });
</script>

<?php
// INCLUDE THE FOOTER
require_once __DIR__ . '/templates/footer.php';
?>