<?php
// templates/footer.php
?>
    </div> 
    </div> <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <script>
        /**
         * This function runs all page-level JavaScript.
         * We run it on the first load, and every time Turbo loads a page.
         */
        function initializePageComponents() {

            // --- 1. FIX THE ICONS (The "Race Condition" Fix) ---
            // We add a 50ms delay. This gives Turbo time to
            // finish swapping the page content *before* we
            // try to draw the icons.
            setTimeout(() => {
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }, 50); // 50ms is instant for a user


            // --- 2. RUN YOUR THEME TOGGLE LOGIC ---
            const themeToggleCheckbox = document.getElementById('theme-toggle-checkbox');
            
            if (themeToggleCheckbox) {
                // Function to apply theme based on checkbox state
                const applyTheme = () => {
                    if (themeToggleCheckbox.checked) {
                        document.documentElement.classList.add('dark');
                        localStorage.setItem('theme', 'dark');
                    } else {
                        document.documentElement.classList.remove('dark');
                        localStorage.setItem('theme', 'light');
                    }
                };

                // Set the initial state of the checkbox
                if (document.documentElement.classList.contains('dark')) {
                    themeToggleCheckbox.checked = true;
                } else {
                    themeToggleCheckbox.checked = false;
                }

                // Only add the listener ONE time
                if (!themeToggleCheckbox.hasAttribute('listener-added')) {
                    themeToggleCheckbox.addEventListener('change', applyTheme);
                    themeToggleCheckbox.setAttribute('listener-added', 'true');
                }
            }
        }

        // --- EVENT LISTENERS ---

        // Run on the VERY FIRST load
        document.addEventListener('DOMContentLoaded', initializePageComponents);

        // Run EVERY time Turbo loads a new page
        document.addEventListener('turbo:load', initializePageComponents);

    </script>
    
    <script src="js/app.js" defer></script>

</body>
</html>