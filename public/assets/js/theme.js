(function () {
    const themeKey = 'ra-theme';
    const sizeKey = 'ra-size';
    const allowedSizes = ['auto', 's', 'm', 'l', 'xl', 'xxl'];
    const root = document.documentElement;

    const getTheme = () => localStorage.getItem(themeKey) || 'teal';

    const getSizePref = () => {
        const pref = localStorage.getItem(sizeKey) || 'auto';
        return allowedSizes.includes(pref) ? pref : 'auto';
    };

    const autoSize = () => {
        const width = window.innerWidth;
        if (width < 1280) {
            return 's';
        }
        if (width < 1540) {
            return 'm';
        }
        if (width < 1800) {
            return 'l';
        }
        if (width < 2200) {
            return 'xl';
        }
        return 'xxl';
    };

    const applySize = (pref) => {
        const nextPref = allowedSizes.includes(pref) ? pref : 'auto';
        const size = nextPref === 'auto' ? autoSize() : nextPref;
        localStorage.setItem(sizeKey, nextPref);
        root.setAttribute('data-size-pref', nextPref);
        root.setAttribute('data-size', size);
        root.classList.toggle('is-compact', size === 's' || size === 'm');
        syncSizeButtons();
    };

    const setTheme = (theme) => {
        const nextTheme = theme === 'indigo' ? 'indigo' : 'teal';
        localStorage.setItem(themeKey, nextTheme);
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

    const syncSizeButtons = () => {
        const activePref = getSizePref();
        document.querySelectorAll('[data-size-set]').forEach((button) => {
            const isActive = button.getAttribute('data-size-set') === activePref;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    };

    let resizeTimer = 0;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => {
            if (getSizePref() === 'auto') {
                applySize('auto');
            }
        }, 120);
    });

    document.addEventListener('DOMContentLoaded', () => {
        syncThemeButtons();
        applySize(getSizePref());

        document.querySelectorAll('[data-theme-set]').forEach((button) => {
            button.addEventListener('click', () => {
                setTheme(button.getAttribute('data-theme-set') || 'teal');
            });
        });

        document.querySelectorAll('[data-size-set]').forEach((button) => {
            button.addEventListener('click', () => {
                applySize(button.getAttribute('data-size-set') || 'auto');
            });
        });
    });
})();
