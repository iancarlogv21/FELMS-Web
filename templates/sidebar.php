<?php
// This file expects a variable named $currentPage to be set on the page that includes it.
?>
<aside id="sidebar" data-turbo-permanent class="w-64 bg-white border-r border-slate-200/80 py-6 fixed h-full shadow-sm z-20 flex flex-col">
    <div class="px-6 mb-10 logo-icon-wrapper flex justify-start">
        <div class="flex items-center gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-[var(--logo-icon-color)] flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
            <div class="overflow-hidden">
                <h1 class="text-2xl font-extrabold tracking-wider logo-text whitespace-nowrap text-[var(--accent-color)]">FELMS</h1>
                <p class="text-xs mt-1 logo-text whitespace-nowrap text-[var(--text-secondary)]">Fast & Efficient LMS</p>
            </div>
        </div>
    </div>

    <nav class="flex-1 flex flex-col w-full px-4 space-y-1">
        
        <div class="<?= ($currentPage === 'dashboard') ? 'sidebar-active' : '' ?>">
            <a href="dashboard.php" class="sidebar-button">
                <i data-lucide="layout-dashboard" class="sidebar-icon w-5 h-5"></i>
                <span class="ml-4 nav-text whitespace-nowrap">Dashboard</span>
            </a>
        </div>
        
        <div class="px-3 pt-4 pb-2">
            <span class="text-xs font-semibold text-[var(--text-secondary)] uppercase nav-text">Manage</span>
        </div>
        <div class="<?= ($currentPage === 'books') ? 'sidebar-active' : '' ?>">
            <a href="books.php" class="sidebar-button"><i data-lucide="book" class="sidebar-icon w-5 h-5"></i><span class="ml-4 nav-text whitespace-nowrap">Books</span></a>
        </div>
        <div class="<?= ($currentPage === 'students') ? 'sidebar-active' : '' ?>">
            <a href="student.php" class="sidebar-button"><i data-lucide="users" class="sidebar-icon w-5 h-5"></i><span class="ml-4 nav-text whitespace-nowrap">Students</span></a>
        </div>
        <div class="<?= ($currentPage === 'borrow') ? 'sidebar-active' : '' ?>">
            <a href="borrow.php" class="sidebar-button"><i data-lucide="book-up" class="sidebar-icon w-5 h-5"></i><span class="ml-4 nav-text whitespace-nowrap">Issue Book</span></a>
        </div>
        <div class="<?= ($currentPage === 'return') ? 'sidebar-active' : '' ?>">
            <a href="return.php" class="sidebar-button"><i data-lucide="book-down" class="sidebar-icon w-5 h-5"></i><span class="ml-4 nav-text whitespace-nowrap">Return Book</span></a>
        </div>

        <?php
            $reportPages = ['active_borrowings', 'overdue_books', 'penalty_report'];
            $isReportActive = in_array($currentPage, $reportPages);
        ?>
        <div x-data="{ open: <?= $isReportActive ? 'true' : 'false' ?> }">
            <button @click="open = !open" class="sidebar-button w-full justify-between <?= $isReportActive ? 'sidebar-active-parent' : '' ?>">
                <span class="flex items-center">
                    <i data-lucide="bar-chart-3" class="sidebar-icon w-5 h-5"></i>
                    <span class="ml-4 nav-text whitespace-nowrap">Reports</span>
                </span>
                <i data-lucide="chevron-down" class="sidebar-icon w-4 h-4 nav-text transition-transform" :class="{'rotate-180': open}"></i>
            </button>
            <ul x-show="open" x-transition class="pt-1 pl-4 space-y-1 nav-text">
                <li class="<?= ($currentPage === 'active_borrowings') ? 'sidebar-active' : '' ?>">
                    <a href="active_borrowings.php" class="sidebar-button !py-2.5"><i data-lucide="list-checks" class="sidebar-icon w-5 h-5"></i><span class="ml-4 nav-text whitespace-nowrap">Active Borrows</span></a>
                </li>
                <li class="<?= ($currentPage === 'overdue_books') ? 'sidebar-active' : '' ?>">
                    <a href="overdue_books.php" class="sidebar-button !py-2.5"><i data-lucide="alert-triangle" class="sidebar-icon w-5 h-5"></i><span class="ml-4 nav-text whitespace-nowrap">Overdue Report</span></a>
                </li>
                <li class="<?= ($currentPage === 'penalty_report') ? 'sidebar-active' : '' ?>">
                    <a href="penalty_report.php" class="sidebar-button !py-2.5"><i data-lucide="coins" class="sidebar-icon w-5 h-5"></i><span class="ml-4 nav-text whitespace-nowrap">Penalty Report</span></a>
                </li>
            </ul>
        </div>
        
        <?php
            $logPages = ['attendance', 'activitylog'];
            $isLogActive = in_array($currentPage, $logPages);
        ?>
        <div x-data="{ open: <?= $isLogActive ? 'true' : 'false' ?> }">
            <button @click="open = !open" class="sidebar-button w-full justify-between <?= $isLogActive ? 'sidebar-active-parent' : '' ?>">
                <span class="flex items-center">
                    <i data-lucide="clipboard-list" class="sidebar-icon w-5 h-5"></i>
                    <span class="ml-4 nav-text whitespace-nowrap">Logs</span>
                </span>
                <i data-lucide="chevron-down" class="sidebar-icon w-4 h-4 nav-text transition-transform" :class="{'rotate-180': open}"></i>
            </button>
            <ul x-show="open" x-transition class="pt-1 pl-4 space-y-1 nav-text">
                <li class="<?= ($currentPage === 'attendance') ? 'sidebar-active' : '' ?>">
                    <a href="attendance_log.php" class="sidebar-button !py-2.5"><i data-lucide="user-check" class="sidebar-icon w-5 h-5"></i><span class="ml-4 nav-text whitespace-nowrap">Attendance</span></a>
                </li>
                <li class="<?= ($currentPage === 'activitylog') ? 'sidebar-active' : '' ?>">
                    <a href="activitylog.php" class="sidebar-button !py-2.5"><i data-lucide="file-text" class="sidebar-icon w-5 h-5"></i><span class="ml-4 nav-text whitespace-nowrap">Activity Log</span></a>
                </li>
            </ul>
        </div>
    </nav>

    <div class="w-full px-4 mt-auto pt-4 border-t border-[var(--border-color)]">
        <div class="theme-toggle-container w-full flex items-center justify-between p-3 rounded-lg mb-2">
            <span class="nav-text whitespace-nowrap font-medium text-[var(--text-secondary)]">Toggle Theme</span>
            <input type="checkbox" id="theme-toggle-checkbox">
            <label for="theme-toggle-checkbox" class="theme-toggle">
                <span class="toggle-button">
                  <span class="crater crater-1"></span><span class="crater crater-2"></span><span class="crater crater-3"></span><span class="crater crater-4"></span><span class="crater crater-5"></span><span class="crater crater-6"></span><span class="crater crater-7"></span>
                </span>
                <span class="star star-1"></span><span class="star star-2"></span><span class="star star-3"></span><span class="star star-4"></span><span class="star star-5"></span><span class="star star-6"></span><span class="star star-7"></span><span class="star star-8"></span>
            </label>
        </div>

        <a href="logout.php" class="sidebar-button">
            <i data-lucide="log-out" class="sidebar-icon w-5 h-5"></i>
            <span class="ml-4 nav-text whitespace-nowrap">Log Out</span>
        </a>
         
        <button id="sidebarToggle" class="sidebar-button">
            <i data-lucide="chevrons-left" class="sidebar-icon w-5 h-5" id="toggleIcon"></i>
            <span class="ml-4 nav-text whitespace-nowrap">Collapse</span>
        </button>
    </div>
</aside>