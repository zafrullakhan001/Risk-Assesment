document.addEventListener('DOMContentLoaded', () => {
    const list = document.getElementById('project-list');
    const switcher = document.getElementById('project-list-view-switcher');

    const storageKey = 'project-list-view-v2';
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
        if (list instanceof HTMLElement) {
            list.dataset.projectView = next;
        }
        document.documentElement.setAttribute('data-project-list-view', next);
        if (switcher instanceof HTMLElement) {
            switcher.querySelectorAll('[data-project-view]').forEach((button) => {
                const active = button.getAttribute('data-project-view') === next;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }
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

    if (list instanceof HTMLElement && switcher instanceof HTMLElement) {
        applyView(readView());

        switcher.addEventListener('click', (event) => {
            const button = event.target.closest('[data-project-view]');
            if (!(button instanceof HTMLElement)) {
                return;
            }
            setView(button.getAttribute('data-project-view') || defaultView);
        });
    }

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

    const syncBulkForm = (formId) => {
        const form = document.getElementById(formId);
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        const boxes = [...document.querySelectorAll('input[type="checkbox"][name="assessment_ids[]"]')]
            .filter((input) => input instanceof HTMLInputElement
                && (input.getAttribute('form') === formId || input.form === form)
                && !input.disabled);
        const selected = boxes.filter((input) => input.checked).length;
        document.querySelectorAll('[data-bulk-count="' + formId + '"]').forEach((label) => {
            label.textContent = selected + ' selected';
        });
        document.querySelectorAll('.js-bulk-select-all[data-bulk-form="' + formId + '"]').forEach((toggle) => {
            if (!(toggle instanceof HTMLInputElement)) {
                return;
            }
            toggle.checked = boxes.length > 0 && selected === boxes.length;
            toggle.indeterminate = selected > 0 && selected < boxes.length;
        });
        document.querySelectorAll('[data-bulk-submit="' + formId + '"]').forEach((button) => {
            if (button instanceof HTMLButtonElement) {
                button.disabled = selected === 0;
            }
        });
    };

    document.querySelectorAll('.js-bulk-select-all[data-bulk-form="bulk-projects-form"]').forEach((toggle) => {
        toggle.addEventListener('change', () => {
            const formId = toggle.getAttribute('data-bulk-form') || '';
            const form = document.getElementById(formId);
            if (!(form instanceof HTMLFormElement) || !(toggle instanceof HTMLInputElement)) {
                return;
            }
            document.querySelectorAll('input[type="checkbox"][name="assessment_ids[]"]').forEach((input) => {
                if (input instanceof HTMLInputElement
                    && (input.getAttribute('form') === formId || input.form === form)
                    && !input.disabled) {
                    input.checked = toggle.checked;
                }
            });
            syncBulkForm(formId);
        });
    });

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)
            || target.type !== 'checkbox'
            || target.name !== 'assessment_ids[]') {
            return;
        }
        const formId = target.getAttribute('form') || (target.form ? target.form.id : '');
        if (formId) {
            syncBulkForm(formId);
        }
    });

    syncBulkForm('bulk-projects-form');
});
