<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// --- NEW HELPER FUNCTIONS ---

/**
 * Parses a user agent string to get browser and OS.
 * @param string $userAgent The user agent string.
 * @return array An array with 'browser' and 'os'.
 */
function parseUserAgent(string $userAgent): array {
    $browser = "Unknown Browser";
    $os = "Unknown OS";

    // Get Operating System
    if (preg_match('/windows nt 10/i', $userAgent)) $os = 'Windows 11/10';
    elseif (preg_match('/windows nt 6.3/i', $userAgent)) $os = 'Windows 8.1';
    elseif (preg_match('/windows nt 6.2/i', $userAgent)) $os = 'Windows 8';
    elseif (preg_match('/windows nt 6.1/i', $userAgent)) $os = 'Windows 7';
    elseif (preg_match('/macintosh|mac os x/i', $userAgent)) $os = 'macOS';
    elseif (preg_match('/linux/i', $userAgent)) $os = 'Linux';
    elseif (preg_match('/android/i', $userAgent)) $os = 'Android';
    elseif (preg_match('/iphone|ipad|ipod/i', $userAgent)) $os = 'iOS';

    // Get Browser
    if (preg_match('/msie/i', $userAgent) && !preg_match('/opera/i', $userAgent)) $browser = 'Internet Explorer';
    elseif (preg_match('/firefox/i', $userAgent)) $browser = 'Firefox';
    elseif (preg_match('/chrome/i', $userAgent)) $browser = 'Chrome';
    elseif (preg_match('/safari/i', $userAgent)) $browser = 'Safari';
    elseif (preg_match('/opera/i', $userAgent)) $browser = 'Opera';

    return ['browser' => $browser, 'os' => $os];
}

/**
 * Returns an SVG icon based on the OS.
 * @param string $os The name of the operating system.
 * @return string The SVG code for the icon.
 */
function getDeviceIcon(string $os): string {
    $iconColor = 'currentColor';
    if (strpos(strtolower($os), 'windows') !== false) {
        // Windows Icon
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'.$iconColor.'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>';
    } elseif (strpos(strtolower($os), 'macos') !== false || strpos(strtolower($os), 'ios') !== false) {
        // Apple Device Icon
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'.$iconColor.'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>';
    } elseif (strpos(strtolower($os), 'android') !== false) {
        // Android Icon
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'.$iconColor.'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>';
    } else {
        // Generic Desktop Icon
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'.$iconColor.'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>';
    }
}


$pageTitle = 'Login History - FELMS';
$currentPage = 'loginhistory';

$db_error = null;
$loginHistory = [];
$timezone = new DateTimeZone('Asia/Manila');

// --- Filtering Logic (No changes) ---
$currentYear = date('Y');
$filterYear = $_GET['filter_year'] ?? $currentYear;
$filterMonth = $_GET['filter_month'] ?? 'all';
$filterDay = $_GET['filter_day'] ?? 'all';
$filter = [];

try {
    if ($filterYear !== 'all') {
        if ($filterMonth !== 'all') {
            if ($filterDay !== 'all') {
                $startOfDay = new DateTime("$filterYear-$filterMonth-$filterDay 00:00:00", $timezone);
                $endOfDay = (clone $startOfDay)->setTime(23, 59, 59);
                $filter['timestamp'] = [
                    '$gte' => new MongoDB\BSON\UTCDateTime($startOfDay),
                    '$lte' => new MongoDB\BSON\UTCDateTime($endOfDay)
                ];
            } else {
                $startOfMonth = new DateTime("$filterYear-$filterMonth-01 00:00:00", $timezone);
                $endOfMonth = (clone $startOfMonth)->modify('last day of this month')->setTime(23, 59, 59);
                $filter['timestamp'] = [
                    '$gte' => new MongoDB\BSON\UTCDateTime($startOfMonth),
                    '$lte' => new MongoDB\BSON\UTCDateTime($endOfMonth)
                ];
            }
        } else {
            $startOfYear = new DateTime("$filterYear-01-01 00:00:00", $timezone);
            $endOfYear = new DateTime("$filterYear-12-31 23:59:59", $timezone);
            $filter['timestamp'] = [
                '$gte' => new MongoDB\BSON\UTCDateTime($startOfYear),
                '$lte' => new MongoDB\BSON\UTCDateTime($endOfYear)
            ];
        }
    }

    $db = Database::getInstance();
    $loginHistoryCollection = $db->login_history();
    $cursor = $loginHistoryCollection->find($filter, ['sort' => ['timestamp' => -1], 'limit' => 500]);

    foreach ($cursor as $entry) {
        $ua_details = parseUserAgent($entry['user_agent'] ?? 'Unknown');
        $entry['browser'] = $ua_details['browser'];
        $entry['os'] = $ua_details['os'];
        $entry['device_icon'] = getDeviceIcon($ua_details['os']);
        $loginHistory[] = $entry;
    }

} catch (Exception $e) {
    $db_error = "Error fetching login history: " . $e->getMessage();
}

// --- Prepare options for filter dropdowns (No changes here) ---
$availableYears = range($currentYear, $currentYear - 5);
$availableMonths = ['all' => 'All Months', '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April', '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August', '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'];
$availableDays = ['all' => 'All Days'];
if ($filterYear !== 'all' && $filterMonth !== 'all') {
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$filterMonth, (int)$filterYear);
    for ($i = 1; $i <= $daysInMonth; $i++) {
        $dayVal = str_pad($i, 2, '0', STR_PAD_LEFT);
        $availableDays[$dayVal] = $dayVal;
    }
}

require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/sidebar.php';
?>

<main id="main-content" class="flex-1 p-6 lg:p-8">
    <header class="mb-8">
        <h1 class="text-3xl font-bold text-primary">Login Activity</h1>
        <p class="text-secondary">A record of all login attempts made to the system.</p>
    </header>

    <?php if ($db_error): ?>
        <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-6 border border-red-200">
            <p class="font-bold">Database Error:</p>
            <p><?= htmlspecialchars($db_error) ?></p>
        </div>
    <?php endif; ?>

    <div class="bg-card p-6 rounded-2xl shadow-md border border-theme mb-8">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
            <div>
                <label for="filter_year" class="form-label text-secondary">Year</label>
                <select name="filter_year" id="filter_year" class="form-input bg-body border-theme" onchange="this.form.submit()">
                    <option value="all">All Years</option>
                    <?php foreach ($availableYears as $year): ?>
                        <option value="<?= $year ?>" <?= ($filterYear == $year) ? 'selected' : '' ?>><?= $year ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="filter_month" class="form-label text-secondary">Month</label>
                <select name="filter_month" id="filter_month" class="form-input bg-body border-theme" onchange="this.form.submit()" <?= $filterYear === 'all' ? 'disabled' : '' ?>>
                    <?php foreach ($availableMonths as $value => $label): ?>
                        <option value="<?= $value ?>" <?= ($filterMonth == $value) ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="filter_day" class="form-label text-secondary">Day</label>
                <select name="filter_day" id="filter_day" class="form-input bg-body border-theme" onchange="this.form.submit()" <?= $filterMonth === 'all' ? 'disabled' : '' ?>>
                    <?php foreach ($availableDays as $value => $label): ?>
                        <option value="<?= $value ?>" <?= ($filterDay == $value) ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <a href="loginhistory.php" class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus:ring-4 focus:ring-slate-500">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"> <path d="M21 2v6h-6"></path> <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path> </svg>
                <span>Reset</span>
            </a>
        </form>
    </div>

    <div class="bg-card rounded-2xl shadow-md border border-theme">
        <div class="p-6 border-b border-theme">
            <h2 class="text-xl font-semibold text-primary">Where You're Logged In</h2>
        </div>
        
        <?php if (empty($loginHistory) && !$db_error): ?>
            <div class="text-center py-16">
                <i data-lucide="inbox" class="w-16 h-16 mx-auto text-secondary mb-4"></i>
                <h3 class="text-2xl font-semibold text-primary">No Login Activity Found</h3>
                <p class="text-secondary mt-2">Try adjusting your filters or check back later.</p>
            </div>
        <?php else: ?>
            <ul class="divide-y divide-theme">
                <?php foreach ($loginHistory as $entry): ?>
                    <li class="p-6 flex items-center gap-4 hover:bg-body transition-colors duration-200">
    <div class="text-secondary">
        <?= $entry['device_icon'] ?>
    </div>
    <div class="flex-1">
        <p class="font-semibold text-primary">
            <?= htmlspecialchars($entry['os']) ?> &middot; <?= htmlspecialchars($entry['browser']) ?>
        </p>
        <p class="text-sm text-primary">
            <?= htmlspecialchars($entry['location'] ?? 'Unknown Location') ?>
        </p>
        <p class="text-sm text-secondary mt-1">
            IP: <span class="font-mono"><?= htmlspecialchars($entry['ip_address'] ?? 'N/A') ?></span>
        </p>
    </div>
    <div class="text-right">
        <?php if ($entry['status'] === 'Success'): ?>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                Success
            </span>
        <?php else: ?>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                Failed
            </span>
        <?php endif; ?>
        <p class="text-sm text-secondary mt-1">
            <?= $entry['timestamp']->toDateTime()->setTimezone($timezone)->format('F j, Y \a\t h:i A') ?>
        </p>
    </div>
</li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/templates/footer.php'; ?>