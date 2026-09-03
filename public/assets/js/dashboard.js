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
        ['section', 'status', 'risk', 'q', 'changed', 'owner', 'timeline', 'gap', 'response'].forEach((key) => next.delete(key));
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
        const responseFilter = document.getElementById(`${prefix}filter-response`);
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
            if (params.get('response') && responseFilter) responseFilter.value = params.get('response');
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
            const responseValue = responseFilter ? responseFilter.value.trim().toLowerCase() : '';

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
                const response = (row.dataset.response || 'open').toLowerCase();
                const actionable = row.dataset.actionable === '1';
                const hasComment = row.dataset.hasComment === '1';

                const matchesGap =
                    gapValue === '' ||
                    (gapValue === 'owner' && row.dataset.missingOwner === '1') ||
                    (gapValue === 'timeline' && row.dataset.missingTimeline === '1') ||
                    (gapValue === 'mitigation' && row.dataset.missingMitigation === '1');

                const matchesResponse =
                    responseValue === '' ||
                    (responseValue === 'needs' && actionable && response === 'open') ||
                    (responseValue === 'commented' && hasComment) ||
                    (responseValue !== 'needs' && responseValue !== 'commented' && response === responseValue);

                const matches =
                    (sectionValue === '' || section === sectionValue) &&
                    (statusValue === '' || status === statusValue) &&
                    (riskValue === '' || risk === riskValue) &&
                    (searchValue === '' || search.includes(searchValue)) &&
                    (changedValue === '' || (changedValue === 'changed' && changed)) &&
                    (ownerValue === '' || owners.split('|').includes(ownerValue.toLowerCase())) &&
                    (timelineValue === '' || timeline === timelineValue.toLowerCase()) &&
                    matchesGap &&
                    matchesResponse;

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
                    isActive = !sectionValue && !statusValue && !riskValue && !searchValue && !changedValue && !ownerValue && !timelineValue && !gapValue && !responseValue;
                } else if (filterType === 'status') {
                    isActive = statusFilter.value === filterValue && !sectionValue && !riskValue;
                } else if (filterType === 'risk') {
                    isActive = riskFilter.value === filterValue && !sectionValue && !statusValue;
                }
                tile.classList.toggle('is-active', isActive);
                tile.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });

            // Drop selection on rows that are no longer listed
            rows.forEach((row) => {
                if (row.classList.contains('hidden')) {
                    const box = row.querySelector('.row-select');
                    if (box) {
                        box.checked = false;
                    }
                }
            });
            if (typeof window.refreshBulkSelectionBars === 'function') {
                window.refreshBulkSelectionBars(tableId);
            }

            if (pushState) {
                syncUrlFilters(scope, {
                    section: sectionFilter.value,
                    status: statusFilter.value,
                    risk: riskFilter.value,
                    q: searchFilter.value,
                    changed: changedFilter ? changedFilter.value : '',
                    response: responseFilter ? responseFilter.value : '',
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
            if (responseFilter) responseFilter.value = '';

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

        [sectionFilter, statusFilter, riskFilter, searchFilter, changedFilter, responseFilter].filter(Boolean).forEach((element) => {
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

    const csrfToken = document.body.dataset.csrfToken || '';
    const responseStorageKey = `ra-item-responses-${assessmentId || 'local'}`;
    let localResponses = {};
    try {
        localResponses = JSON.parse(localStorage.getItem(responseStorageKey) || '{}');
    } catch (error) {
        localResponses = {};
    }

    const updateOpenResponseCount = () => {
        const openCount = Array.from(document.querySelectorAll('#response-tracker-table .item-response-action'))
            .filter((node) => node.value === 'open').length;
        const label = document.getElementById('response-open-count');
        if (label) {
            label.textContent = `${openCount} open`;
        }
    };

    const syncResponseWidgets = (itemKey, action, comment, { refreshFilters = false, source = null } = {}) => {
        document.querySelectorAll('.item-response').forEach((widget) => {
            if (widget.dataset.itemKey !== itemKey) {
                return;
            }
            const actionSelect = widget.querySelector('.item-response-action');
            const commentField = widget.querySelector('.item-response-comment');
            if (actionSelect && widget !== source && actionSelect.value !== action) {
                actionSelect.value = action;
            }
            if (commentField && widget !== source && commentField.value !== comment) {
                commentField.value = comment;
            }
            const row = widget.closest('tr');
            if (row) {
                row.dataset.response = action;
                row.dataset.hasComment = comment.trim() ? '1' : '0';
            }
        });
        updateOpenResponseCount();
        if (refreshFilters) {
            Object.values(filterControllers).forEach((controller) => {
                if (typeof controller.applyFilters === 'function') {
                    controller.applyFilters(false, false);
                }
            });
        }
    };

    const persistResponse = async (itemKey, action, comment, saveLabel, sourceWidget, refreshFilters) => {
        localResponses[itemKey] = { action, comment };
        localStorage.setItem(responseStorageKey, JSON.stringify(localResponses));
        syncResponseWidgets(itemKey, action, comment, { refreshFilters, source: sourceWidget });

        if (saveLabel) {
            saveLabel.hidden = false;
            saveLabel.textContent = Number(assessmentId) > 0 ? 'Saving…' : 'Saved locally';
        }

        if (!assessmentId || Number(assessmentId) <= 0) {
            if (saveLabel) {
                saveLabel.textContent = 'Saved locally';
                window.setTimeout(() => {
                    saveLabel.hidden = true;
                }, 1200);
            }
            return;
        }

        const body = new URLSearchParams({
            action: 'save_item_response',
            csrf_token: csrfToken,
            assessment_id: String(assessmentId),
            item_key: itemKey,
            response_action: action,
            comment,
        });

        try {
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body,
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Save failed');
            }
            if (saveLabel) {
                saveLabel.textContent = 'Saved';
                window.setTimeout(() => {
                    saveLabel.hidden = true;
                }, 1200);
            }
        } catch (error) {
            if (saveLabel) {
                saveLabel.hidden = false;
                saveLabel.textContent = 'Save failed';
            }
        }
    };

    document.querySelectorAll('.item-response').forEach((widget) => {
        const itemKey = widget.dataset.itemKey;
        if (!itemKey) {
            return;
        }
        const actionSelect = widget.querySelector('.item-response-action');
        const commentField = widget.querySelector('.item-response-comment');
        const saveLabel = widget.querySelector('.item-response-save');
        const saved = localResponses[itemKey];

        if (saved && Number(assessmentId) <= 0) {
            if (actionSelect && saved.action) {
                actionSelect.value = saved.action;
            }
            if (commentField && typeof saved.comment === 'string') {
                commentField.value = saved.comment;
            }
            syncResponseWidgets(itemKey, actionSelect?.value || 'open', commentField?.value || '', {
                refreshFilters: true,
                source: widget,
            });
        }

        let commentTimer = null;
        const queueSave = (refreshFilters) => {
            const action = actionSelect ? actionSelect.value : 'open';
            const comment = commentField ? commentField.value : '';
            persistResponse(itemKey, action, comment, saveLabel, widget, refreshFilters);
        };

        if (actionSelect) {
            actionSelect.addEventListener('change', () => queueSave(true));
        }
        if (commentField) {
            commentField.addEventListener('input', () => {
                if (commentTimer) {
                    window.clearTimeout(commentTimer);
                }
                commentTimer = window.setTimeout(() => queueSave(false), 500);
            });
            commentField.addEventListener('blur', () => queueSave(false));
        }
    });

    updateOpenResponseCount();

    const refreshBulkSelectionBars = (tableId = null) => {
        document.querySelectorAll('.bulk-response-bar').forEach((bar) => {
            const targetTableId = bar.dataset.bulkTable;
            if (tableId && targetTableId !== tableId) {
                return;
            }
            const table = document.getElementById(targetTableId);
            const countLabel = bar.querySelector('.bulk-selected-count');
            const selectAll = bar.querySelector('.bulk-select-all-toggle');
            if (!table || !countLabel) {
                return;
            }
            const visibleBoxes = Array.from(table.querySelectorAll('tbody tr:not(.hidden) .row-select'));
            const selected = visibleBoxes.filter((box) => box.checked);
            countLabel.textContent = `${selected.length} selected`;
            if (selectAll) {
                selectAll.checked = visibleBoxes.length > 0 && selected.length === visibleBoxes.length;
                selectAll.indeterminate = selected.length > 0 && selected.length < visibleBoxes.length;
            }
        });
    };
    window.refreshBulkSelectionBars = refreshBulkSelectionBars;

    const persistResponsesBulk = async (itemKeys, action, comment, statusLabel) => {
        itemKeys.forEach((itemKey) => {
            localResponses[itemKey] = { action, comment };
            syncResponseWidgets(itemKey, action, comment, { refreshFilters: false, source: null });
        });
        localStorage.setItem(responseStorageKey, JSON.stringify(localResponses));
        Object.values(filterControllers).forEach((controller) => {
            if (typeof controller.applyFilters === 'function') {
                controller.applyFilters(false, false);
            }
        });
        updateOpenResponseCount();

        if (statusLabel) {
            statusLabel.hidden = false;
            statusLabel.textContent = Number(assessmentId) > 0 ? 'Saving…' : `Updated ${itemKeys.length} locally`;
        }

        if (!assessmentId || Number(assessmentId) <= 0) {
            if (statusLabel) {
                statusLabel.textContent = `Updated ${itemKeys.length} locally`;
                window.setTimeout(() => {
                    statusLabel.hidden = true;
                }, 1600);
            }
            return true;
        }

        const body = new URLSearchParams({
            action: 'save_item_responses_bulk',
            csrf_token: csrfToken,
            assessment_id: String(assessmentId),
            response_action: action,
            comment,
            item_keys: JSON.stringify(itemKeys),
        });

        try {
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body,
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Bulk save failed');
            }
            if (statusLabel) {
                statusLabel.textContent = `Updated ${payload.saved || itemKeys.length}`;
                window.setTimeout(() => {
                    statusLabel.hidden = true;
                }, 1600);
            }
            return true;
        } catch (error) {
            if (statusLabel) {
                statusLabel.hidden = false;
                statusLabel.textContent = 'Bulk save failed';
            }
            return false;
        }
    };

    document.querySelectorAll('.bulk-response-bar').forEach((bar) => {
        const table = document.getElementById(bar.dataset.bulkTable || '');
        const selectAll = bar.querySelector('.bulk-select-all-toggle');
        const applyBtn = bar.querySelector('.bulk-response-apply');
        const actionSelect = bar.querySelector('.bulk-response-action');
        const commentField = bar.querySelector('.bulk-response-comment');
        const statusLabel = bar.querySelector('.bulk-response-status');
        if (!table) {
            return;
        }

        const visibleSelectable = () => Array.from(table.querySelectorAll('tbody tr:not(.hidden) .row-select'));

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                visibleSelectable().forEach((box) => {
                    box.checked = selectAll.checked;
                });
                refreshBulkSelectionBars(table.id);
            });
        }

        table.addEventListener('change', (event) => {
            if (event.target && event.target.classList.contains('row-select')) {
                refreshBulkSelectionBars(table.id);
            }
        });

        if (applyBtn) {
            applyBtn.addEventListener('click', async () => {
                const keys = visibleSelectable()
                    .filter((box) => box.checked)
                    .map((box) => box.value)
                    .filter(Boolean);
                if (keys.length === 0) {
                    if (statusLabel) {
                        statusLabel.hidden = false;
                        statusLabel.textContent = 'Select at least one listed row';
                    }
                    return;
                }
                const action = actionSelect ? actionSelect.value : 'open';
                const comment = commentField ? commentField.value : '';
                const label = actionSelect?.selectedOptions?.[0]?.textContent || action;
                if (!window.confirm(`Update ${keys.length} selected row${keys.length === 1 ? '' : 's'} to “${label}”?`)) {
                    return;
                }
                applyBtn.disabled = true;
                await persistResponsesBulk(keys, action, comment, statusLabel);
                applyBtn.disabled = false;
                refreshBulkSelectionBars(table.id);
            });
        }
    });

    refreshBulkSelectionBars();

    const evaluationForm = document.getElementById('final-evaluation-form');
    if (evaluationForm) {
        const statusEl = document.getElementById('final-eval-status');
        const savedLabel = document.getElementById('final-eval-saved-label');
        const badge = document.getElementById('golive-status-badge');
        const execSummary = document.querySelector('.exec-summary');
        const saveBtn = document.getElementById('btn-save-evaluation');

        const applyEvaluationUi = (evaluation) => {
            const ready = !!evaluation.ready_to_golive;
            const name = evaluation.evaluator_name || '';
            document.body.classList.toggle('is-ready-golive', ready);
            if (execSummary) {
                execSummary.classList.toggle('is-ready-golive', ready);
            }
            let heroPill = document.querySelector('.hero-project .golive-pill');
            if (ready) {
                if (!heroPill) {
                    const project = document.querySelector('.hero-project');
                    if (project) {
                        heroPill = document.createElement('span');
                        heroPill.className = 'golive-pill';
                        const spectrum = project.querySelector('.risk-spectrum');
                        if (spectrum) {
                            project.insertBefore(heroPill, spectrum);
                        } else {
                            project.appendChild(heroPill);
                        }
                    }
                }
                if (heroPill) {
                    heroPill.textContent = 'Ready to go-live';
                }
            } else if (heroPill) {
                heroPill.remove();
            }
            if (badge) {
                badge.hidden = false;
                badge.classList.toggle('is-pending', !ready);
                badge.textContent = `${ready ? 'Ready to go-live' : 'Not ready to go-live'}${name ? ` · ${name}` : ''}`;
            }
            if (savedLabel && evaluation.updated_at) {
                savedLabel.textContent = `Saved ${evaluation.updated_at}`;
            }
        };

        evaluationForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!assessmentId || Number(assessmentId) <= 0) {
                if (statusEl) {
                    statusEl.hidden = false;
                    statusEl.textContent = 'Save requires a stored assessment';
                }
                return;
            }

            const name = document.getElementById('eval-name')?.value.trim() || '';
            const email = document.getElementById('eval-email')?.value.trim() || '';
            const notes = document.getElementById('eval-notes')?.value || '';
            const ready = !!document.getElementById('eval-ready')?.checked;

            if (!name || !email) {
                if (statusEl) {
                    statusEl.hidden = false;
                    statusEl.textContent = 'Name and email are required';
                }
                return;
            }

            const body = new URLSearchParams({
                action: 'save_final_evaluation',
                csrf_token: csrfToken,
                assessment_id: String(assessmentId),
                evaluator_name: name,
                evaluator_email: email,
                notes,
                ready_to_golive: ready ? '1' : '0',
            });

            if (saveBtn) {
                saveBtn.disabled = true;
            }
            if (statusEl) {
                statusEl.hidden = false;
                statusEl.textContent = 'Saving…';
            }

            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body,
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || 'Save failed');
                }
                applyEvaluationUi(payload.evaluation || {
                    ready_to_golive: ready,
                    evaluator_name: name,
                    updated_at: 'just now',
                });
                if (statusEl) {
                    statusEl.textContent = 'Saved';
                    window.setTimeout(() => {
                        statusEl.hidden = true;
                    }, 1600);
                }
            } catch (error) {
                if (statusEl) {
                    statusEl.hidden = false;
                    statusEl.textContent = error.message || 'Save failed';
                }
            } finally {
                if (saveBtn) {
                    saveBtn.disabled = false;
                }
            }
        });
    }

    if (params.get('tab') === 'actions' || window.location.hash === '#version-history' || window.location.hash === '#item-responses') {
        activateTab('actions', false);
        if (window.location.hash === '#version-history') {
            document.getElementById('version-history')?.scrollIntoView({ behavior: 'smooth' });
        }
        if (window.location.hash === '#item-responses') {
            document.getElementById('item-responses')?.scrollIntoView({ behavior: 'smooth' });
        }
    }

    if (window.location.hash === '#final-evaluation') {
        document.getElementById('final-evaluation')?.scrollIntoView({ behavior: 'smooth' });
    }
});
