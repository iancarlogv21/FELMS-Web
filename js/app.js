document.addEventListener('DOMContentLoaded', function () {
    // Get all the necessary elements from the page
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('main-content');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const toggleIcon = document.getElementById('toggleIcon');
    const toggleText = sidebarToggle?.querySelector('.nav-text');

    // This function applies the visual changes for the collapsed/expanded state
    const applySidebarState = (isCollapsed) => {
        sidebar.classList.toggle('collapsed', isCollapsed);
        mainContent?.classList.toggle('collapsed', isCollapsed);

        const iconName = isCollapsed ? 'chevrons-right' : 'chevrons-left';
        const buttonText = isCollapsed ? 'Expand' : 'Collapse';
        
        toggleIcon?.setAttribute('data-lucide', iconName);
        if (toggleText) {
            toggleText.textContent = buttonText;
        }

        // Re-render the Lucide icons to apply the change
        if (window.lucide) {
            window.lucide.createIcons();
        }
    };

    // This function handles the click event
    const handleToggleClick = () => {
        const isNowCollapsed = !sidebar.classList.contains('collapsed');
        // Save the state in the browser's memory
        localStorage.setItem('sidebarCollapsed', isNowCollapsed);
        applySidebarState(isNowCollapsed);
    };

    // --- Initialization ---
    // Check if a state was saved from a previous visit
    const isInitiallyCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    applySidebarState(isInitiallyCollapsed);

    // Attach the click event listener to the button
    sidebarToggle?.addEventListener('click', handleToggleClick);
});