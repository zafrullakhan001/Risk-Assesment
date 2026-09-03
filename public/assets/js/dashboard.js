document.addEventListener('DOMContentLoaded', () => {
    const tabButtons = Array.from(document.querySelectorAll('.dash-tab'));
    const panels = Array.from(document.querySelectorAll('.dash-panel'));

    const activateTab = (tabName) => {
        tabButtons.forEach((button) => {
            const isActive = button.dataset.tab === tabName;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panels.forEach((panel) => {
            const isActive = panel.dataset.panel === tabName;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });
    };

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activateTab(button.dataset.tab || 'architecture');
        });
    });

    const initFilterScope = (scope, tableId, prefix) => {
        const table = document.getElementById(tableId);
        if (!table) {
            return;
        }

        const sectionFilter = document.getElementById(`${prefix}filter-section`);
        const statusFilter = document.getElementById(`${prefix}filter-status`);
        const riskFilter = document.getElementById(`${prefix}filter-risk`);
        const searchFilter = document.getElementById(`${prefix}filter-search`);
        const clearFilters = document.getElementById(`${prefix}clearFilters`);
        const countLabel = document.getElementById(`${prefix}filter-count`);
        const register = document.querySelector(`[data-filter-scope="${scope}"].table-card`);
        const panel = table.closest('.dash-panel');
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        const kpiTiles = Array.from((panel || document).querySelectorAll(`[data-filter-scope="${scope}"] .kpi-clickable`));
        const clickableFilters = Array.from((panel || document).querySelectorAll(
            `.kpi-clickable, .legend-clickable, .bar-row-clickable, .donut-segment, .pie-segment`
        )).filter((element) => {
            const host = element.closest('[data-filter-scope], .dash-panel');
            if (!host) {
                return scope === 'architecture';
            }
            if (host.dataset.filterScope) {
                return host.dataset.filterScope === scope;
            }
            return host === panel;
        });

        if (!sectionFilter || !statusFilter || !riskFilter || !searchFilter || !countLabel) {
            return;
        }

        const applyFilters = (scrollToTable = false) => {
            const sectionValue = sectionFilter.value.trim().toLowerCase();
            const statusValue = statusFilter.value.trim().toLowerCase();
            const riskValue = riskFilter.value.trim().toLowerCase();
            const searchValue = searchFilter.value.trim().toLowerCase();

            let visibleCount = 0;
            let firstVisibleRow = null;

            rows.forEach((row) => {
                const section = (row.dataset.section || '').toLowerCase();
                const status = (row.dataset.status || '').toLowerCase();
                const risk = (row.dataset.risk || '').toLowerCase();
                const search = row.dataset.search || '';

                const matches =
                    (sectionValue === '' || section === sectionValue) &&
                    (statusValue === '' || status === statusValue) &&
                    (riskValue === '' || risk === riskValue) &&
                    (searchValue === '' || search.includes(searchValue));

                row.classList.toggle('hidden', !matches);
                row.classList.remove('row-highlight');

                if (matches) {
                    visibleCount += 1;
                    if (!firstVisibleRow) {
                        firstVisibleRow = row;
                    }
                }
            });

            countLabel.textContent = `${visibleCount} shown`;
            syncActiveTiles();

            if (scrollToTable && register) {
                register.scrollIntoView({ behavior: 'smooth', block: 'start' });
                if (firstVisibleRow) {
                    window.setTimeout(() => {
                        firstVisibleRow.classList.add('row-highlight');
                        firstVisibleRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 250);
                }
            }
        };

        const syncActiveTiles = () => {
            kpiTiles.forEach((tile) => {
                const filterType = tile.dataset.filterType || '';
                const filterValue = tile.dataset.filterValue || '';
                let isActive = false;

                if (filterType === 'all') {
                    isActive =
                        sectionFilter.value === '' &&
                        statusFilter.value === '' &&
                        riskFilter.value === '' &&
                        searchFilter.value.trim() === '';
                } else if (filterType === 'status') {
                    isActive = statusFilter.value === filterValue && sectionFilter.value === '' && riskFilter.value === '';
                } else if (filterType === 'risk') {
                    isActive = riskFilter.value === filterValue && sectionFilter.value === '' && statusFilter.value === '';
                }

                tile.classList.toggle('is-active', isActive);
                tile.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        };

        const setFilterFromTrigger = (filterType, filterValue, scrollToTable = true) => {
            if (filterType === 'all') {
                sectionFilter.value = '';
                statusFilter.value = '';
                riskFilter.value = '';
                searchFilter.value = '';
            } else if (filterType === 'section') {
                sectionFilter.value = filterValue;
                statusFilter.value = '';
                riskFilter.value = '';
            } else if (filterType === 'status') {
                statusFilter.value = filterValue;
                sectionFilter.value = '';
                riskFilter.value = '';
            } else if (filterType === 'risk') {
                riskFilter.value = filterValue;
                sectionFilter.value = '';
                statusFilter.value = '';
            }

            applyFilters(scrollToTable);
        };

        clickableFilters.forEach((element) => {
            element.addEventListener('click', () => {
                const filterType = element.dataset.filterType;
                const filterValue = element.dataset.filterValue ?? '';
                if (!filterType) {
                    return;
                }
                setFilterFromTrigger(filterType, filterValue, true);
            });
        });

        [sectionFilter, statusFilter, riskFilter, searchFilter].forEach((element) => {
            element.addEventListener('input', () => applyFilters(false));
            element.addEventListener('change', () => applyFilters(false));
        });

        if (clearFilters) {
            clearFilters.addEventListener('click', () => {
                setFilterFromTrigger('all', '', false);
            });
        }

        applyFilters(false);
    };

    initFilterScope('architecture', 'risk-table', '');
    initFilterScope('due_diligence', 'dd-table', 'dd-');
});
