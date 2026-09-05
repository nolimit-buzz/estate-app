/**
 * Global Theme Manager (Day & Night Mode Toggle)
 * Handles theme persistence using localStorage and data-theme attribute
 */

(function () {
    // Determine initial theme
    const savedTheme = localStorage.getItem('estate_app_theme');
    const systemPrefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const initialTheme = savedTheme || (systemPrefersDark ? 'dark' : 'light');

    // Apply theme immediately to prevent white flash
    document.documentElement.setAttribute('data-theme', initialTheme);

    window.addEventListener('DOMContentLoaded', () => {
        applyTheme(initialTheme);
        bindThemeToggleButtons();
    });

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        if (theme === 'dark') {
            document.body.classList.add('dark-mode');
        } else {
            document.body.classList.remove('dark-mode');
        }
        localStorage.setItem('estate_app_theme', theme);
        updateToggleIcons(theme);
    }

    function updateToggleIcons(theme) {
        const toggleBtns = document.querySelectorAll('.theme-toggle-btn');
        toggleBtns.forEach(btn => {
            const icon = btn.querySelector('i');
            const textSpan = btn.querySelector('.theme-text');
            if (theme === 'dark') {
                if (icon) icon.className = 'fa-solid fa-sun text-warning';
                if (textSpan) textSpan.textContent = 'Light Mode';
                btn.setAttribute('title', 'Switch to Light Mode');
            } else {
                if (icon) icon.className = 'fa-solid fa-moon text-indigo';
                if (textSpan) textSpan.textContent = 'Dark Mode';
                btn.setAttribute('title', 'Switch to Dark Mode');
            }
        });
    }

    function bindThemeToggleButtons() {
        const toggleBtns = document.querySelectorAll('.theme-toggle-btn');
        toggleBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const currentTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                applyTheme(newTheme);
            });
        });
    }

    // Expose global helper
    window.toggleAppTheme = function () {
        const currentTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        applyTheme(newTheme);
    };
})();
