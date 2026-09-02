document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('risk-table');
    if (!table) {
        return;
    }

    const sectionFilter = document.getElementById('filter-section');
    const statusFilter = document.getElementById('filter-status');
    const riskFilter = document.getElementById('filter-risk');
    const searchFilter = document.getElementById('filter-search');
    const clearFilters = document.getElementById('clearFilters');
    const countLabel = document.getElementById('filter-count');
    const riskRegister = document.getElementById('risk-register');
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    const kpiTiles = Array.from(document.querySelectorAll('.kpi-clickable'));
    const clickableFilters = Array.from(document.querySelectorAll(
        '.kpi-clickable, .legend-clickable, .bar-row-clickable, .donut-segment, .pie-segment'
    ));

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

        if (scrollToTable && riskRegister) {
            riskRegister.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
});
