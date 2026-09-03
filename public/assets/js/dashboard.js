document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const tabButtons = Array.from(document.querySelectorAll('.dash-tab'));
    const panels = Array.from(document.querySelectorAll('.dash-panel'));
    const assessmentId = document.body.dataset.assessmentId || '0';

    const activateTab = (tabName, pushState = true) => {
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

        if (pushState) {
            const next = new URLSearchParams(window.location.search);
            next.set('tab', tabName);
            const query = next.toString();
            window.history.replaceState({}, '', `${window.location.pathname}?${query}${window.location.hash}`);
        }
    };

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activateTab(button.dataset.tab || 'architecture');
        });
    });

    const initialTab = params.get('tab');
    if (initialTab && document.querySelector(`.dash-tab[data-tab="${initialTab}"]`)) {
        activateTab(initialTab, false);
    }

    const filterControllers = {};

    const syncUrlFilters = (scope, values) => {
        if (scope !== 'architecture') {
            return;
        }
        const next = new URLSearchParams(window.location.search);
        ['section', 'status', 'risk', 'q', 'changed', 'owner', 'timeline', 'gap'].forEach((key) => next.delete(key));
        Object.entries(values).forEach(([key, value]) => {
            if (value) {
                next.set(key, value);
            }
        });
        if (!next.get('view') && next.get('id')) {
            next.set('view', '1');
        }
        window.history.replaceState({}, '', `${window.location.pathname}?${next.toString()}${window.location.hash}`);
    };

    const initFilterScope = (scope, tableId, prefix) => {
        const table = document.getElementById(tableId);
        if (!table) {
            return;
        }

        const sectionFilter = document.getElementById(`${prefix}filter-section`);
        const statusFilter = document.getElementById(`${prefix}filter-status`);
        const riskFilter = document.getElementById(`${prefix}filter-risk`);
        const searchFilter = document.getElementById(`${prefix}filter-search`);
        const changedFilter = document.getElementById(`${prefix}filter-changed`);
        const clearFilters = document.getElementById(`${prefix}clearFilters`);
        const countLabel = document.getElementById(`${prefix}filter-count`);
        const register = document.querySelector(`[data-filter-scope="${scope}"].table-card`);
        const panel = table.closest('.dash-panel');
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        const kpiTiles = Array.from((panel || document).querySelectorAll(`[data-filter-scope="${scope}"] .kpi-clickable`));

        let ownerValue = '';
        let timelineValue = '';
        let gapValue = '';

        if (!sectionFilter || !statusFilter || !riskFilter || !searchFilter || !countLabel) {
            return;
        }

        if (scope === 'architecture') {
            if (params.get('section')) sectionFilter.value = params.get('section');
            if (params.get('status')) statusFilter.value = params.get('status');
            if (params.get('risk')) riskFilter.value = params.get('risk');
            if (params.get('q')) searchFilter.value = params.get('q');
            if (params.get('changed') && changedFilter) changedFilter.value = params.get('changed');
            ownerValue = params.get('owner') || '';
            timelineValue = params.get('timeline') || '';
            gapValue = params.get('gap') || '';
        }

        const applyFilters = (scrollToTable = false, pushState = true) => {
            const sectionValue = sectionFilter.value.trim().toLowerCase();
            const statusValue = statusFilter.value.trim().toLowerCase();
            const riskValue = riskFilter.value.trim().toLowerCase();
            const searchValue = searchFilter.value.trim().toLowerCase();
            const changedValue = changedFilter ? changedFilter.value.trim().toLowerCase() : '';

            let visibleCount = 0;
            let firstVisibleRow = null;

            rows.forEach((row) => {
                const section = (row.dataset.section || '').toLowerCase();
                const status = (row.dataset.status || '').toLowerCase();
                const risk = (row.dataset.risk || '').toLowerCase();
                const search = row.dataset.search || '';
                const owners = (row.dataset.owner || '').toLowerCase();
                const timeline = (row.dataset.timeline || '').toLowerCase();
                const changed = row.dataset.changed === '1';

                const matchesGap =
                    gapValue === '' ||
                    (gapValue === 'owner' && row.dataset.missingOwner === '1') ||
                    (gapValue === 'timeline' && row.dataset.missingTimeline === '1') ||
                    (gapValue === 'mitigation' && row.dataset.missingMitigation === '1');

                const matches =
                    (sectionValue === '' || section === sectionValue) &&
                    (statusValue === '' || status === statusValue) &&
                    (riskValue === '' || risk === riskValue) &&
                    (searchValue === '' || search.includes(searchValue)) &&
                    (changedValue === '' || (changedValue === 'changed' && changed)) &&
                    (ownerValue === '' || owners.split('|').includes(ownerValue.toLowerCase())) &&
                    (timelineValue === '' || timeline === timelineValue.toLowerCase()) &&
                    matchesGap;

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

            kpiTiles.forEach((tile) => {
                const filterType = tile.dataset.filterType || '';
                const filterValue = tile.dataset.filterValue || '';
                let isActive = false;
                if (filterType === 'all') {
                    isActive = !sectionValue && !statusValue && !riskValue && !searchValue && !changedValue && !ownerValue && !timelineValue && !gapValue;
                } else if (filterType === 'status') {
                    isActive = statusFilter.value === filterValue && !sectionValue && !riskValue;
                } else if (filterType === 'risk') {
                    isActive = riskFilter.value === filterValue && !sectionValue && !statusValue;
                }
                tile.classList.toggle('is-active', isActive);
                tile.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });

            if (pushState) {
                syncUrlFilters(scope, {
                    section: sectionFilter.value,
                    status: statusFilter.value,
                    risk: riskFilter.value,
                    q: searchFilter.value,
                    changed: changedFilter ? changedFilter.value : '',
                    owner: ownerValue,
                    timeline: timelineValue,
                    gap: gapValue,
                    tab: document.querySelector('.dash-tab.is-active')?.dataset.tab || 'architecture',
                });
            }

            if (scrollToTable && register) {
                activateTab(scope === 'due_diligence' ? 'due-diligence' : 'architecture', false);
                register.scrollIntoView({ behavior: 'smooth', block: 'start' });
                if (firstVisibleRow) {
                    window.setTimeout(() => {
                        firstVisibleRow.classList.add('row-highlight');
                        firstVisibleRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 250);
                }
            }
        };

        const setFilterFromTrigger = (filterType, filterValue, scrollToTable = true) => {
            ownerValue = '';
            timelineValue = '';
            gapValue = '';
            if (changedFilter) changedFilter.value = '';

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
            } else if (filterType === 'owner') {
                ownerValue = filterValue;
                sectionFilter.value = '';
                statusFilter.value = '';
                riskFilter.value = '';
                activateTab('architecture', false);
            } else if (filterType === 'timeline') {
                timelineValue = filterValue;
                sectionFilter.value = '';
                statusFilter.value = '';
                riskFilter.value = '';
                activateTab('architecture', false);
            } else if (filterType === 'gap') {
                gapValue = filterValue;
                sectionFilter.value = '';
                statusFilter.value = '';
                riskFilter.value = '';
                activateTab('architecture', false);
            } else if (filterType === 'tab') {
                activateTab(filterValue || 'actions');
                return;
            }

            applyFilters(scrollToTable);
        };

        const clickableFilters = Array.from(document.querySelectorAll(
            '.kpi-clickable, .legend-clickable, .bar-row-clickable, .donut-segment, .pie-segment, .blocker-chip, .owner-row, .timeline-lane'
        )).filter((element) => {
            if (element.classList.contains('blocker-chip') || element.classList.contains('owner-row') || element.classList.contains('timeline-lane')) {
                return scope === 'architecture';
            }
            const host = element.closest('[data-filter-scope], .dash-panel');
            if (!host) {
                return scope === 'architecture';
            }
            if (host.dataset.filterScope) {
                return host.dataset.filterScope === scope;
            }
            return host === panel;
        });

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

        [sectionFilter, statusFilter, riskFilter, searchFilter, changedFilter].filter(Boolean).forEach((element) => {
            element.addEventListener('input', () => applyFilters(false));
            element.addEventListener('change', () => applyFilters(false));
        });

        if (clearFilters) {
            clearFilters.addEventListener('click', () => setFilterFromTrigger('all', '', false));
        }

        filterControllers[scope] = {
            applyFilters,
            setFilterFromTrigger,
            getVisibleRows: () => rows.filter((row) => !row.classList.contains('hidden')),
            table,
        };

        applyFilters(false, false);
    };

    initFilterScope('architecture', 'risk-table', '');
    initFilterScope('due_diligence', 'dd-table', 'dd-');

    document.querySelectorAll('[data-expandable]').forEach((node) => {
        node.addEventListener('click', () => {
            node.classList.toggle('is-expanded');
        });
    });

    const presentationBtn = document.getElementById('btn-presentation');
    if (presentationBtn) {
        presentationBtn.addEventListener('click', () => {
            document.body.classList.toggle('is-presentation');
            presentationBtn.textContent = document.body.classList.contains('is-presentation')
                ? 'Exit presentation'
                : 'Presentation mode';
        });
    }

    const printBtn = document.getElementById('btn-print');
    if (printBtn) {
        printBtn.addEventListener('click', () => {
            document.body.classList.add('is-presentation');
            window.print();
        });
    }

    const exportBtn = document.getElementById('btn-export-csv');
    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            const controller = filterControllers.architecture;
            if (!controller) {
                return;
            }
            const rows = controller.getVisibleRows();
            const headers = Array.from(controller.table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
            const lines = [headers.map((h) => `"${h.replaceAll('"', '""')}"`).join(',')];
            rows.forEach((row) => {
                const cells = Array.from(row.children).map((td) => `"${td.innerText.replaceAll('"', '""').replaceAll(/\s+/g, ' ').trim()}"`);
                lines.push(cells.join(','));
            });
            const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `risk-register-${assessmentId || 'export'}.csv`;
            link.click();
            URL.revokeObjectURL(link.href);
        });
    }

    const exceptionKey = `ra-exceptions-${assessmentId}`;
    const savedExceptions = JSON.parse(localStorage.getItem(exceptionKey) || '{}');
    document.querySelectorAll('.exception-status').forEach((select) => {
        const findingId = select.dataset.findingId;
        if (savedExceptions[findingId]) {
            select.value = savedExceptions[findingId];
        }
        select.addEventListener('change', () => {
            savedExceptions[findingId] = select.value;
            localStorage.setItem(exceptionKey, JSON.stringify(savedExceptions));
            const openCount = Array.from(document.querySelectorAll('.exception-status'))
                .filter((node) => node.value === 'Open').length;
            const label = document.getElementById('exception-open-count');
            if (label) {
                label.textContent = `${openCount} open`;
            }
        });
    });

    if (params.get('tab') === 'actions' || window.location.hash === '#version-history') {
        activateTab('actions', false);
        if (window.location.hash === '#version-history') {
            document.getElementById('version-history')?.scrollIntoView({ behavior: 'smooth' });
        }
    }
});
