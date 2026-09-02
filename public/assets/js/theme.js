(function () {
    const storageKey = 'ra-theme';
    const root = document.documentElement;

    const getTheme = () => localStorage.getItem(storageKey) || 'teal';

    const setTheme = (theme) => {
        const nextTheme = theme === 'indigo' ? 'indigo' : 'teal';
        localStorage.setItem(storageKey, nextTheme);
        root.setAttribute('data-theme', nextTheme);
        syncThemeButtons();
    };

    const syncThemeButtons = () => {
        const activeTheme = getTheme();
        document.querySelectorAll('[data-theme-set]').forEach((button) => {
            const isActive = button.getAttribute('data-theme-set') === activeTheme;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        syncThemeButtons();

        document.querySelectorAll('[data-theme-set]').forEach((button) => {
            button.addEventListener('click', () => {
                setTheme(button.getAttribute('data-theme-set') || 'teal');
            });
        });
    });
})();
