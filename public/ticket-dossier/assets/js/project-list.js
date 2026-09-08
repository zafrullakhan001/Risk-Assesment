document.addEventListener('DOMContentLoaded', () => {
    const list = document.getElementById('project-list');
    const switcher = document.getElementById('project-list-view-switcher');
    if (!(list instanceof HTMLElement) || !(switcher instanceof HTMLElement)) {
        return;
    }

    const storageKey = 'td-project-list-view-v1';
    const allowed = new Set(['cards', 'table', 'strip']);
    const defaultView = 'table';

    const normalizeView = (view) => {
        if (view === 'list') {
            return 'strip';
        }
        return allowed.has(view) ? view : defaultView;
    };

    const readView = () => {
        try {
            const stored = window.localStorage.getItem(storageKey);
            if (stored) {
                return normalizeView(stored);
            }
        } catch (error) {
            // Keep the default table view when storage is unavailable.
        }
        return normalizeView(document.documentElement.getAttribute('data-project-list-view') || defaultView);
    };

    const applyView = (view) => {
        const next = normalizeView(view);
        list.dataset.projectView = next;
        document.documentElement.setAttribute('data-project-list-view', next);
        switcher.querySelectorAll('[data-project-view]').forEach((button) => {
            const active = button.getAttribute('data-project-view') === next;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    };

    const setView = (view) => {
        const next = normalizeView(view);
        try {
            window.localStorage.setItem(storageKey, next);
        } catch (error) {
            // Ignore storage failures and keep the in-memory view.
        }
        applyView(next);
    };

    applyView(readView());

    switcher.addEventListener('click', (event) => {
        const button = event.target.closest('[data-project-view]');
        if (!(button instanceof HTMLElement)) {
            return;
        }
        setView(button.getAttribute('data-project-view') || defaultView);
    });

    const filterToggle = document.getElementById('project-filter-toggle');
    const filterRow = document.getElementById('project-table-filters');
    if (filterToggle instanceof HTMLButtonElement && filterRow instanceof HTMLElement) {
        const setFiltersOpen = (open) => {
            filterRow.hidden = !open;
            filterRow.classList.toggle('is-collapsed', !open);
            filterToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            filterToggle.classList.toggle('is-active', open);
            filterToggle.textContent = open ? 'Hide filters' : 'Show filters';
            if (open) {
                const firstInput = filterRow.querySelector('input[type="search"]');
                if (firstInput instanceof HTMLInputElement) {
                    firstInput.focus();
                }
            }
        };

        filterToggle.addEventListener('click', () => {
            setFiltersOpen(filterRow.hidden);
        });
    }
});
