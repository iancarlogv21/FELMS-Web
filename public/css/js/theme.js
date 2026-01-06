// public/js/theme.js
document.addEventListener('DOMContentLoaded', () => {
    const themeSwitcher = document.getElementById('theme-switcher');
    const themeIcons = {
        light: document.getElementById('theme-icon-light'),
        dark: document.getElementById('theme-icon-dark'),
        system: document.getElementById('theme-icon-system'),
    };

    const applyTheme = (theme) => {
        localStorage.setItem('theme', theme);
        if (theme === 'system') {
            const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', systemPrefersDark);
        } else {
            document.documentElement.classList.toggle('dark', theme === 'dark');
        }
        updateIcons(theme);
    };

    const updateIcons = (theme) => {
        for (const key in themeIcons) {
            themeIcons[key]?.classList.toggle('text-red-500', key === theme);
            themeIcons[key]?.classList.toggle('text-gray-400', key !== theme);
        }
    };

    themeSwitcher?.addEventListener('click', (e) => {
        const theme = e.target.closest('[data-theme]')?.dataset.theme;
        if (theme) {
            applyTheme(theme);
        }
    });

    // Apply theme on initial load
    applyTheme(localStorage.getItem('theme') || 'system');
});