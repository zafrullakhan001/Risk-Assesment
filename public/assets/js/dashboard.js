document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const tabButtons = Array.from(document.querySelectorAll('.dash-tab'));
    const panels = Array.from(document.querySelectorAll('.dash-panel'));
    const assessmentId = document.body.dataset.assessmentId || '0';
    const csrfToken = document.body.dataset.csrfToken || '';

    let initialGoliveGates = null;
    try {
        initialGoliveGates = JSON.parse(document.body.dataset.goliveGates || '{}');
    } catch (error) {
        initialGoliveGates = null;
    }

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
                    (responseValue === 'addressed' && actionable && response !== 'open') ||
                    (responseValue === 'commented' && hasComment) ||
                    (responseValue !== 'needs' && responseValue !== 'commented' && responseValue !== 'addressed' && response === responseValue);

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
            } else if (filterType === 'response') {
                if (responseFilter) {
                    responseFilter.value = filterValue;
                }
                sectionFilter.value = '';
                statusFilter.value = '';
                riskFilter.value = '';
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
            } else if (filterType === 'action_tab') {
                activateTab('actions', true);
                if (typeof window.activateActionTab === 'function') {
                    window.activateActionTab(filterValue || 'risks', true);
                }
                return;
            }

            applyFilters(scrollToTable);
        };

        const clickableFilters = Array.from(document.querySelectorAll(
            '.kpi-clickable, .legend-clickable, .bar-row-clickable, .donut-segment, .pie-segment, .blocker-chip, .owner-row, .timeline-lane, [data-filter-type="action_tab"]'
        )).filter((element) => {
            if (element.dataset.filterType === 'action_tab' || element.classList.contains('blocker-chip')) {
                return scope === 'architecture';
            }
            if (element.classList.contains('owner-row') || element.classList.contains('timeline-lane')) {
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

    const parseIntSafe = (value) => {
        const n = Number.parseInt(String(value ?? '').trim(), 10);
        return Number.isFinite(n) ? n : 0;
    };

    const getOpenMetricValue = (key) => {
        const node = document.querySelector(`.exec-metrics [data-progress-open="${key}"]`);
        if (!node) return 0;
        return parseIntSafe(node.textContent);
    };

    const computeGoliveGatesClient = () => {
        const highOpen = getOpenMetricValue('high');
        const openExceptions = Array.from(document.querySelectorAll('.exception-status'))
            .filter((node) => node.value === 'Open').length;
        const notesFilled = (document.getElementById('eval-notes')?.value.trim() || '') !== '';
        const rules = [
            {
                id: 'high_risks',
                label: 'No open High risks',
                passed: highOpen === 0,
                detail: highOpen === 0
                    ? 'All High risks addressed'
                    : `${highOpen} High risk${highOpen === 1 ? '' : 's'} still open`,
                filter_type: 'action_tab',
                filter_value: 'risks',
            },
            {
                id: 'exceptions',
                label: 'All exceptions closed, approved, or expired',
                passed: openExceptions === 0,
                detail: openExceptions === 0
                    ? 'No open governance exceptions'
                    : `${openExceptions} exception${openExceptions === 1 ? '' : 's'} still open`,
                filter_type: 'action_tab',
                filter_value: 'exceptions',
            },
            {
                id: 'notes',
                label: 'Evaluator notes completed',
                passed: notesFilled,
                detail: notesFilled ? 'Notes provided' : 'Add final evaluation notes before sign-off',
                filter_type: 'action_tab',
                filter_value: 'signoff',
            },
        ];
        const readyAllowed = rules.every((rule) => rule.passed);

        return {
            ready_allowed: readyAllowed,
            rules,
            residual: { high: highOpen, open_findings: openExceptions },
        };
    };

    const applyGoliveGates = (gate) => {
        if (!gate || !Array.isArray(gate.rules)) {
            return;
        }

        const container = document.getElementById('golive-gates');
        if (container) {
            container.classList.toggle('is-ready', !!gate.ready_allowed);
            container.classList.toggle('is-blocked', !gate.ready_allowed);
            const icon = container.querySelector('.golive-gates-icon');
            const title = container.querySelector('.golive-gates-head h4');
            if (icon) {
                icon.textContent = gate.ready_allowed ? '✅' : '🚧';
            }
            if (title) {
                title.textContent = gate.ready_allowed
                    ? 'All gates passed'
                    : 'Gates must pass before sign-off';
            }
            gate.rules.forEach((rule) => {
                const item = container.querySelector(`.golive-gate[data-gate-id="${rule.id}"]`);
                if (!item) {
                    return;
                }
                item.classList.toggle('is-pass', !!rule.passed);
                item.classList.toggle('is-fail', !rule.passed);
                const status = item.querySelector('.golive-gate-status');
                if (status) {
                    status.textContent = rule.passed ? '✅' : '❌';
                }
                const detail = item.querySelector(`[data-gate-detail="${rule.id}"]`);
                if (detail) {
                    detail.textContent = rule.detail || '';
                }
                const fixBtn = item.querySelector('.gate-fix-link');
                if (fixBtn) {
                    fixBtn.hidden = !!rule.passed;
                }
            });
            const summary = document.getElementById('golive-gates-summary');
            if (summary) {
                summary.textContent = gate.ready_allowed
                    ? 'You may mark this version ready to go-live once the evaluation is saved.'
                    : 'Resolve each failing gate, then save with Ready to go-live checked.';
            }
        }

        const readyCheckbox = document.getElementById('eval-ready');
        const hint = document.getElementById('golive-gate-hint');
        if (readyCheckbox && !readyCheckbox.checked) {
            readyCheckbox.disabled = !gate.ready_allowed;
        }
        if (hint) {
            hint.hidden = !!gate.ready_allowed;
        }

        try {
            document.body.dataset.goliveGates = JSON.stringify(gate);
        } catch (error) {
            // ignore
        }
    };

    const refreshGoliveGates = () => {
        applyGoliveGates(computeGoliveGatesClient());
    };
    window.refreshGoliveGates = refreshGoliveGates;

    const persistFindingStatus = async (findingId, status) => {
        const body = new URLSearchParams({
            action: 'save_finding_status',
            csrf_token: csrfToken,
            assessment_id: String(assessmentId),
            finding_id: findingId,
            status,
        });

        const response = await fetch('index.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body,
        });
        const text = await response.text();
        let payload = {};
        try {
            payload = text ? JSON.parse(text) : {};
        } catch (error) {
            throw new Error('Save failed');
        }
        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'Save failed');
        }

        return payload;
    };

    const setExceptionSaveLabel = (select, message, isError = false) => {
        const label = select.parentElement?.querySelector('.exception-status-save');
        if (!label) {
            return;
        }
        label.hidden = !message;
        label.textContent = message;
        label.classList.toggle('is-error', !!isError);
    };

    const updateExecSummaryFromExceptions = () => {
        const exceptionSelects = document.querySelectorAll('.exception-status');
        if (!exceptionSelects || exceptionSelects.length === 0) {
            return;
        }

        const openExceptions = Array.from(exceptionSelects).filter((node) => node.value === 'Open').length;

        const execSummaryEl = document.querySelector('.exec-summary');
        const execCopyEl = document.getElementById('exec-copy');
        const scoreEl = document.querySelector('.exec-score strong');
        const verdictEl = document.getElementById('exec-verdict') || document.querySelector('.exec-copy-view h3');
        const summaryEl = document.getElementById('exec-summary-text') || document.querySelector('.exec-copy-view p');

        const openMetricSpan = Array.from(document.querySelectorAll('.exec-metrics span')).find((span) => span.textContent.trim().endsWith('Open exceptions'));
        const openMetricBold = openMetricSpan ? openMetricSpan.querySelector('b') : null;

        const currentOpenExceptions = openMetricBold ? parseIntSafe(openMetricBold.textContent) : openExceptions;

        if (openMetricBold) {
            openMetricBold.textContent = String(openExceptions);
        }

        const blockerChip = document.querySelector('.blocker-chip[data-filter-value="exceptions"] b');
        if (blockerChip) {
            blockerChip.textContent = String(openExceptions);
        }

        if (!scoreEl || !verdictEl || !summaryEl || !execSummaryEl) {
            return;
        }

        const currentScore = parseIntSafe(scoreEl.textContent);
        const newScore = Math.max(0, Math.min(100, currentScore + (currentOpenExceptions - openExceptions) * 8));

        scoreEl.textContent = String(newScore);

        const highOpen = getOpenMetricValue('high');
        const riskOpen = getOpenMetricValue('risk');
        const tbdOpen = getOpenMetricValue('tbd');
        const gapOpen = getOpenMetricValue('gap');

        let band = 'blocked';
        let verdict = 'Not ready for go-live';
        let summary = `Material blockers remain: ${highOpen} High risks, ${riskOpen} Risk items, ${tbdOpen} TBD decisions, and ${openExceptions} open exceptions must be mitigated or formally accepted first.`;

        if (newScore >= 80 && highOpen === 0 && openExceptions === 0) {
            band = 'ready';
            verdict = 'Conditional go-live ready';
            summary = `Residual exposure is limited: ${gapOpen} Gap and ${tbdOpen} TBD items remain, with no open High risks or governance exceptions.`;
        } else if (newScore >= 55) {
            band = 'conditional';
            verdict = 'Proceed only with controls';
            summary = `Do not treat as clear to go live yet: ${highOpen} High risks, ${riskOpen} Risk-status checks, ${tbdOpen} TBD items, and ${openExceptions} open exceptions need owners and closure dates.`;
        }

        execSummaryEl.classList.remove('band-ready', 'band-conditional', 'band-blocked');
        execSummaryEl.classList.add(`band-${band}`);
        if (execCopyEl) {
            execCopyEl.dataset.autoVerdict = verdict;
            execCopyEl.dataset.autoSummary = summary;
        }
        const evalVerdict = document.getElementById('eval-exec-verdict');
        const evalSummary = document.getElementById('eval-exec-summary');
        if (evalVerdict) {
            evalVerdict.placeholder = verdict;
        }
        if (evalSummary) {
            evalSummary.placeholder = summary;
        }
        if (execCopyEl?.dataset.custom === '1') {
            return;
        }
        verdictEl.textContent = verdict;
        summaryEl.textContent = summary;
        const deskVerdict = document.getElementById('exec-verdict-input');
        const deskSummary = document.getElementById('exec-summary-input');
        if (deskVerdict && document.getElementById('exec-summary-form')?.hidden) {
            deskVerdict.value = verdict;
        }
        if (deskSummary && document.getElementById('exec-summary-form')?.hidden) {
            deskSummary.value = summary;
        }
    };

    document.querySelectorAll('.exception-status').forEach((select) => {
        const findingId = select.dataset.findingId;
        if (!findingId) {
            return;
        }
        if (Number(assessmentId) <= 0 && savedExceptions[findingId]) {
            select.value = savedExceptions[findingId];
        }
        let saveGeneration = 0;
        select.addEventListener('change', async () => {
            const openCount = Array.from(document.querySelectorAll('.exception-status'))
                .filter((node) => node.value === 'Open').length;
            const countLabel = document.getElementById('exception-open-count');
            if (countLabel) {
                countLabel.textContent = `${openCount} open`;
            }

            updateExecSummaryFromExceptions();

            const generation = ++saveGeneration;
            const status = select.value;

            if (Number(assessmentId) > 0) {
                setExceptionSaveLabel(select, 'Saving…');
                try {
                    const payload = await persistFindingStatus(findingId, status);
                    if (generation !== saveGeneration) {
                        return;
                    }
                    if (payload.status && payload.status !== select.value) {
                        select.value = payload.status;
                    }
                    setExceptionSaveLabel(select, 'Saved');
                    window.setTimeout(() => {
                        if (generation === saveGeneration) {
                            setExceptionSaveLabel(select, '');
                        }
                    }, 1200);
                    if (payload.gates) {
                        applyGoliveGates(payload.gates);
                    } else {
                        refreshGoliveGates();
                    }
                } catch (error) {
                    if (generation !== saveGeneration) {
                        return;
                    }
                    setExceptionSaveLabel(select, error.message || 'Save failed', true);
                    if (countLabel) {
                        countLabel.textContent = `${openCount} open`;
                    }
                }
                return;
            }

            savedExceptions[findingId] = status;
            localStorage.setItem(exceptionKey, JSON.stringify(savedExceptions));
            setExceptionSaveLabel(select, 'Saved locally');
            window.setTimeout(() => {
                if (generation === saveGeneration) {
                    setExceptionSaveLabel(select, '');
                }
            }, 1200);
            refreshGoliveGates();
        });
    });

    updateExecSummaryFromExceptions();
    if (initialGoliveGates && Array.isArray(initialGoliveGates.rules)) {
        applyGoliveGates(initialGoliveGates);
    } else {
        refreshGoliveGates();
    }

    const responseStorageKey = `ra-item-responses-${assessmentId || 'local'}`;
    let localResponses = {};
    try {
        localResponses = JSON.parse(localStorage.getItem(responseStorageKey) || '{}');
    } catch (error) {
        localResponses = {};
    }

    const updateOpenResponseCount = () => {
        const openCount = Array.from(document.querySelectorAll('.actions-workspace .item-response-action'))
            .filter((node) => node.value === 'open').length;
        const label = document.getElementById('response-open-count');
        if (label) {
            label.textContent = `${openCount} item responses open`;
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
        document.querySelectorAll(`tr[data-item-key]`).forEach((row) => {
            if (row.dataset.itemKey === itemKey) {
                row.dataset.response = action;
                row.dataset.hasComment = comment.trim() ? '1' : '0';
                const pill = row.querySelector('.response-pill');
                if (pill) {
                    const labels = {
                        open: 'Open',
                        take_care: 'Taken care',
                        ignore: 'Ignore',
                        not_applicable: 'Not applicable',
                        closed: 'Closed',
                    };
                    pill.textContent = labels[action] || 'Open';
                    pill.className = `response-pill response-${action}`;
                }
            }
        });
        updateOpenResponseCount();
        refreshProgressDisplays();
        if (refreshFilters) {
            Object.values(filterControllers).forEach((controller) => {
                if (typeof controller.applyFilters === 'function') {
                    controller.applyFilters(false, false);
                }
            });
        }
    };

    const refreshProgressDisplays = () => {
        const computeBuckets = (tableSelector) => {
            const buckets = {
                risk: { total: 0, open: 0, addressed: 0 },
                gap: { total: 0, open: 0, addressed: 0 },
                tbd: { total: 0, open: 0, addressed: 0 },
                high: { total: 0, open: 0, addressed: 0 },
                actionable: { total: 0, open: 0, addressed: 0 },
            };
            document.querySelectorAll(tableSelector).forEach((row) => {
                const status = (row.dataset.status || '').toLowerCase();
                const risk = (row.dataset.risk || '').toLowerCase();
                const addressed = (row.dataset.response || 'open') !== 'open';
                buckets.actionable.total += 1;
                if (addressed) {
                    buckets.actionable.addressed += 1;
                } else {
                    buckets.actionable.open += 1;
                }
                const bump = (name, matches) => {
                    if (!matches) {
                        return;
                    }
                    buckets[name].total += 1;
                    if (addressed) {
                        buckets[name].addressed += 1;
                    } else {
                        buckets[name].open += 1;
                    }
                };
                bump('risk', status === 'risk');
                bump('gap', status === 'gap');
                bump('tbd', status === 'tbd');
                bump('high', risk === 'high');
            });
            return buckets;
        };

        const applyBuckets = (root, buckets) => {
            if (!root) {
                return;
            }
            ['risk', 'gap', 'tbd', 'high'].forEach((key) => {
                const bucket = buckets[key];
                const hasResolution = bucket.addressed > 0 && bucket.total > 0;
                root.querySelectorAll(`[data-progress-display="${key}"]`).forEach((display) => {
                    display.dataset.progressHasResolution = hasResolution ? '1' : '0';
                    const openNode = display.querySelector(`[data-progress-open="${key}"]`);
                    const totalNode = display.querySelector(`[data-progress-total="${key}"]`);
                    const tail = display.querySelector('.kpi-progress-tail');
                    if (openNode) {
                        openNode.textContent = String(hasResolution ? bucket.open : bucket.total);
                    }
                    if (totalNode) {
                        totalNode.textContent = String(bucket.total);
                    }
                    if (tail) {
                        tail.hidden = !hasResolution;
                    }
                });
                root.querySelectorAll(`[data-progress-caption="${key}"]`).forEach((node) => {
                    node.hidden = !hasResolution;
                    if (hasResolution) {
                        node.textContent = `${bucket.addressed} addressed · ${bucket.open} open`;
                    }
                });
            });
        };

        const archBuckets = computeBuckets('#risk-table tr[data-actionable="1"]');
        const ddBuckets = computeBuckets('#dd-table tr[data-actionable="1"]');
        const combined = computeBuckets('#risk-table tr[data-actionable="1"], #dd-table tr[data-actionable="1"]');

        applyBuckets(document.getElementById('kpi-tiles'), archBuckets);
        applyBuckets(document.getElementById('dd-kpi-tiles'), ddBuckets);

        ['risk', 'gap', 'tbd', 'high', 'actionable'].forEach((key) => {
            const bucket = combined[key];
            const hasResolution = bucket.addressed > 0 && bucket.total > 0;
            document.querySelectorAll(`.exec-metrics [data-progress-open="${key}"]`).forEach((node) => {
                node.textContent = String(hasResolution ? bucket.open : bucket.total);
                const tail = node.parentElement?.querySelector('.exec-progress-tail');
                if (tail) {
                    tail.hidden = !hasResolution;
                }
            });
            document.querySelectorAll(`.exec-metrics [data-progress-total="${key}"]`).forEach((node) => {
                node.textContent = String(bucket.total);
            });
            document.querySelectorAll(`[data-progress-addressed="${key}"]`).forEach((node) => {
                node.textContent = String(bucket.addressed);
            });
            document.querySelectorAll(`[data-progress-caption="${key}"]`).forEach((node) => {
                if (node.closest('.kpis')) {
                    return;
                }
                if (key === 'actionable') {
                    node.textContent = `${bucket.open} still open`;
                } else {
                    node.textContent = hasResolution
                        ? `${bucket.addressed} addressed · ${bucket.open} open`
                        : 'No resolutions yet';
                }
            });
            document.querySelectorAll(`[data-progress-fraction="${key}"]`).forEach((node) => {
                node.textContent = hasResolution ? `${bucket.open}/${bucket.total} open` : `${bucket.total} open`;
            });
        });

        const actionable = combined.actionable;
        const bar = document.querySelector('[data-progress-bar="actionable"]');
        if (bar && actionable.total > 0) {
            bar.style.width = `${Math.round((actionable.addressed / actionable.total) * 1000) / 10}%`;
        }

        const setDonut = (id, open, total, addressed, labelWhenOpen, labelWhenPlain, plainValue = null) => {
            const valueNode = document.querySelector(`[data-donut-value="${id}"]`);
            const labelNode = document.querySelector(`[data-donut-label="${id}"]`);
            const wrap = valueNode?.closest('.donut-wrap, .pie-wrap');
            const showFraction = addressed > 0 && total > 0;
            if (valueNode) {
                valueNode.textContent = showFraction
                    ? `${open}/${total}`
                    : String(plainValue !== null ? plainValue : total);
            }
            if (labelNode) {
                labelNode.textContent = showFraction ? labelWhenOpen : labelWhenPlain;
            }
            if (wrap) {
                wrap.classList.toggle('has-fraction', showFraction);
            }
        };
        setDonut('hero-donut', actionable.open, actionable.total, actionable.addressed, 'open residual', 'residual');
        setDonut('status-donut', archBuckets.risk.open, archBuckets.risk.total, archBuckets.risk.addressed, 'open risks', 'risks');
        setDonut('risk-donut', archBuckets.high.open, archBuckets.high.total, archBuckets.high.addressed, 'open high', 'high risk');
        setDonut('dd-status-donut', ddBuckets.risk.open, ddBuckets.risk.total, ddBuckets.risk.addressed, 'open risks', 'risks');
        setDonut('dd-risk-donut', ddBuckets.high.open, ddBuckets.high.total, ddBuckets.high.addressed, 'open high', 'high risk');

        const archChecks = document.querySelectorAll('#risk-table tbody tr').length;
        const ddChecks = document.querySelectorAll('#dd-table tbody tr').length;
        setDonut(
            'section-pie',
            archBuckets.actionable.open,
            archBuckets.actionable.total,
            archBuckets.actionable.addressed,
            'open residual',
            'checks',
            archChecks
        );
        setDonut(
            'dd-section-pie',
            ddBuckets.actionable.open,
            ddBuckets.actionable.total,
            ddBuckets.actionable.addressed,
            'open residual',
            'checks',
            ddChecks
        );

        try {
            document.body.dataset.progress = JSON.stringify(combined);
        } catch (error) {
            // ignore
        }

        refreshGoliveGates();
    };
    window.refreshProgressDisplays = refreshProgressDisplays;

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
            applyItemAttribution(widget, payload);
            refreshGoliveGates();
        } catch (error) {
            if (saveLabel) {
                saveLabel.hidden = false;
                saveLabel.textContent = 'Save failed';
            }
        }
    };

    const formatActorLabel = (entry = {}) => {
        const displayName = String(entry.actor_display_name || entry.updated_by_display_name || '').trim();
        const username = String(entry.actor_username || entry.updated_by_username || '').trim();
        const authSource = String(entry.actor_auth_source || entry.updated_by_auth_source || '').trim().toLowerCase();
        let label = '';
        if (displayName && username && displayName.toLowerCase() !== username.toLowerCase()) {
            label = `${displayName} (${username})`;
        } else {
            label = displayName || username || '';
        }
        if (label && (authSource === 'ldap' || authSource === 'local')) {
            label += ` · ${authSource}`;
        }
        return label;
    };

    const applyItemAttribution = (widget, payload = {}) => {
        if (!widget) {
            return;
        }
        const response = payload.response || {};
        const label = payload.updated_by_label
            || response.updated_by_label
            || formatActorLabel(response)
            || formatActorLabel(payload.history_entry || {});
        const updatedAt = payload.updated_at || response.updated_at || '';
        const attr = widget.querySelector('.item-response-attribution');
        if (attr && (label || updatedAt)) {
            attr.hidden = false;
            attr.textContent = label
                ? `Updated by ${label}${updatedAt ? ` · ${updatedAt}` : ''}`
                : `Updated ${updatedAt}`;
        }
        const historyEntry = payload.history_entry;
        if (historyEntry) {
            prependHistoryEntry(widget.querySelector('.change-history'), historyEntry);
        }
    };

    const prependHistoryEntry = (detailsEl, entry) => {
        if (!detailsEl || !entry) {
            return;
        }
        detailsEl.hidden = false;
        let list = detailsEl.querySelector('.change-history-list');
        if (!list) {
            list = document.createElement('ul');
            list.className = 'change-history-list';
            detailsEl.appendChild(list);
        }
        const li = document.createElement('li');
        const strong = document.createElement('strong');
        strong.textContent = entry.summary || 'Updated';
        const span = document.createElement('span');
        const actorLabel = formatActorLabel(entry) || 'Unknown user';
        span.textContent = `${actorLabel}${entry.created_at ? ` · ${entry.created_at}` : ''}`;
        li.appendChild(strong);
        li.appendChild(span);
        list.insertBefore(li, list.firstChild);
        const summary = detailsEl.querySelector('summary');
        if (summary) {
            const base = summary.textContent.replace(/\s*\(\d+\)\s*$/, '').trim() || 'History';
            summary.textContent = `${base} (${list.children.length})`;
        }
        while (list.children.length > 20) {
            list.removeChild(list.lastElementChild);
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
            const responses = Array.isArray(payload.responses) ? payload.responses : [];
            responses.forEach((row) => {
                const key = row.item_key || '';
                const widget = document.querySelector(`.item-response[data-item-key="${CSS.escape(key)}"]`);
                applyItemAttribution(widget, {
                    response: row,
                    updated_by_label: payload.updated_by_label || row.updated_by_label,
                    updated_at: row.updated_at || payload.updated_at,
                    history_entry: {
                        summary: `${payload.label || 'Updated'}${comment ? ' — comment updated' : ''}`,
                        actor_username: row.updated_by_username,
                        actor_display_name: row.updated_by_display_name,
                        actor_auth_source: row.updated_by_auth_source,
                        created_at: row.updated_at,
                    },
                });
            });
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

    const actionTabButtons = Array.from(document.querySelectorAll('.action-tab'));
    const actionPanels = Array.from(document.querySelectorAll('.action-panel'));
    const activateActionTab = (tabName, pushState = true) => {
        const target = tabName || 'risks';
        actionTabButtons.forEach((button) => {
            const isActive = button.dataset.actionTab === target;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        actionPanels.forEach((panel) => {
            const isActive = panel.dataset.actionPanel === target;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });
        if (pushState) {
            const next = new URLSearchParams(window.location.search);
            next.set('tab', 'actions');
            next.set('action_tab', target);
            window.history.replaceState({}, '', `${window.location.pathname}?${next.toString()}`);
        }
        window.refreshBulkSelectionBars?.();
    };
    window.activateActionTab = activateActionTab;

    actionTabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activateActionTab(button.dataset.actionTab || 'risks');
        });
    });

    // Outside architecture filter scope (desk buttons) still need action_tab handlers
    document.querySelectorAll('[data-filter-type="action_tab"]').forEach((element) => {
        if (element.closest('[data-filter-scope="architecture"]') || element.closest('.dash-panel[data-panel="architecture"]')) {
            return;
        }
        element.addEventListener('click', () => {
            activateTab('actions', true);
            activateActionTab(element.dataset.filterValue || 'risks', true);
            document.getElementById('actions-workspace')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    const applyExecutiveSummaryUi = (executive = {}) => {
        const execCopyEl = document.getElementById('exec-copy');
        const verdictEl = document.getElementById('exec-verdict');
        const summaryEl = document.getElementById('exec-summary-text');
        const customPill = document.getElementById('exec-custom-pill');
        const evalCustomPill = document.getElementById('eval-exec-custom-pill');
        const deskVerdict = document.getElementById('exec-verdict-input');
        const deskSummary = document.getElementById('exec-summary-input');
        const evalVerdict = document.getElementById('eval-exec-verdict');
        const evalSummary = document.getElementById('eval-exec-summary');
        const isCustom = !!executive.is_custom;
        const displayVerdict = executive.verdict || '';
        const displaySummary = executive.summary || '';

        if (execCopyEl) {
            execCopyEl.dataset.custom = isCustom ? '1' : '0';
            if (executive.auto_verdict) {
                execCopyEl.dataset.autoVerdict = executive.auto_verdict;
            }
            if (executive.auto_summary) {
                execCopyEl.dataset.autoSummary = executive.auto_summary;
            }
        }
        if (verdictEl && displayVerdict) {
            verdictEl.textContent = displayVerdict;
        }
        if (summaryEl && displaySummary) {
            summaryEl.textContent = displaySummary;
        }
        if (customPill) {
            customPill.hidden = !isCustom;
        }
        if (evalCustomPill) {
            evalCustomPill.hidden = !isCustom;
        }
        if (deskVerdict) {
            deskVerdict.value = displayVerdict || execCopyEl?.dataset.autoVerdict || '';
        }
        if (deskSummary) {
            deskSummary.value = displaySummary || execCopyEl?.dataset.autoSummary || '';
        }
        if (evalVerdict) {
            evalVerdict.value = executive.custom_verdict || '';
            if (executive.auto_verdict) {
                evalVerdict.placeholder = executive.auto_verdict;
            }
        }
        if (evalSummary) {
            evalSummary.value = executive.custom_summary || '';
            if (executive.auto_summary) {
                evalSummary.placeholder = executive.auto_summary;
            }
        }
    };

    const execSummaryForm = document.getElementById('exec-summary-form');
    const execSummaryView = document.getElementById('exec-summary-view');
    const editExecBtn = document.getElementById('btn-edit-exec-summary');
    const cancelExecBtn = document.getElementById('btn-cancel-exec-summary');
    const resetExecBtn = document.getElementById('btn-reset-exec-summary');
    const execEditStatus = document.getElementById('exec-edit-status');

    const setExecEditStatus = (message, isError = false) => {
        if (!execEditStatus) {
            return;
        }
        execEditStatus.hidden = !message;
        execEditStatus.textContent = message || '';
        execEditStatus.classList.toggle('is-error', !!isError);
    };

    const setExecEditorOpen = (open) => {
        if (execSummaryForm) {
            execSummaryForm.hidden = !open;
        }
        if (execSummaryView) {
            execSummaryView.hidden = !!open;
        }
        if (editExecBtn) {
            editExecBtn.hidden = !!open;
        }
        if (open) {
            const execCopyEl = document.getElementById('exec-copy');
            const deskVerdict = document.getElementById('exec-verdict-input');
            const deskSummary = document.getElementById('exec-summary-input');
            const verdictEl = document.getElementById('exec-verdict');
            const summaryEl = document.getElementById('exec-summary-text');
            if (deskVerdict) {
                deskVerdict.value = verdictEl?.textContent || execCopyEl?.dataset.autoVerdict || '';
            }
            if (deskSummary) {
                deskSummary.value = summaryEl?.textContent || execCopyEl?.dataset.autoSummary || '';
            }
            deskVerdict?.focus();
        }
        setExecEditStatus('');
    };

    const persistExecutiveSummary = async (verdict, summary) => {
        if (!assessmentId || Number(assessmentId) <= 0) {
            throw new Error('Save requires a stored assessment');
        }
        const body = new URLSearchParams({
            action: 'save_executive_summary',
            csrf_token: csrfToken,
            assessment_id: String(assessmentId),
            executive_verdict: verdict,
            executive_summary: summary,
        });
        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body,
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'Save failed');
        }
        return payload;
    };

    if (editExecBtn) {
        editExecBtn.addEventListener('click', () => {
            setExecEditorOpen(true);
        });
    }
    if (cancelExecBtn) {
        cancelExecBtn.addEventListener('click', () => {
            setExecEditorOpen(false);
        });
    }
    if (resetExecBtn) {
        resetExecBtn.addEventListener('click', async () => {
            const execCopyEl = document.getElementById('exec-copy');
            const deskVerdict = document.getElementById('exec-verdict-input');
            const deskSummary = document.getElementById('exec-summary-input');
            if (deskVerdict) {
                deskVerdict.value = execCopyEl?.dataset.autoVerdict || '';
            }
            if (deskSummary) {
                deskSummary.value = execCopyEl?.dataset.autoSummary || '';
            }
            setExecEditStatus('Saving…');
            try {
                const payload = await persistExecutiveSummary('', '');
                applyExecutiveSummaryUi(payload.executive || {
                    verdict: execCopyEl?.dataset.autoVerdict || '',
                    summary: execCopyEl?.dataset.autoSummary || '',
                    auto_verdict: execCopyEl?.dataset.autoVerdict || '',
                    auto_summary: execCopyEl?.dataset.autoSummary || '',
                    custom_verdict: '',
                    custom_summary: '',
                    is_custom: false,
                });
                setExecEditorOpen(false);
            } catch (error) {
                setExecEditStatus(error.message || 'Save failed', true);
            }
        });
    }
    if (execSummaryForm) {
        execSummaryForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const deskVerdict = document.getElementById('exec-verdict-input');
            const deskSummary = document.getElementById('exec-summary-input');
            const execCopyEl = document.getElementById('exec-copy');
            const verdict = (deskVerdict?.value || '').trim();
            const summary = (deskSummary?.value || '').trim();
            const autoVerdict = execCopyEl?.dataset.autoVerdict || '';
            const autoSummary = execCopyEl?.dataset.autoSummary || '';
            const persistVerdict = verdict === autoVerdict ? '' : verdict;
            const persistSummary = summary === autoSummary ? '' : summary;
            const saveBtn = document.getElementById('btn-save-exec-summary');
            if (saveBtn) {
                saveBtn.disabled = true;
            }
            setExecEditStatus('Saving…');
            try {
                const payload = await persistExecutiveSummary(persistVerdict, persistSummary);
                applyExecutiveSummaryUi(payload.executive || {
                    verdict: persistVerdict || autoVerdict,
                    summary: persistSummary || autoSummary,
                    auto_verdict: autoVerdict,
                    auto_summary: autoSummary,
                    custom_verdict: persistVerdict,
                    custom_summary: persistSummary,
                    is_custom: persistVerdict !== '' || persistSummary !== '',
                });
                setExecEditorOpen(false);
            } catch (error) {
                setExecEditStatus(error.message || 'Save failed', true);
            } finally {
                if (saveBtn) {
                    saveBtn.disabled = false;
                }
            }
        });
    }

    const applyExecutivePreset = (button) => {
        const verdict = button.dataset.verdict || '';
        const summary = button.dataset.summary || '';
        const target = button.closest('[data-exec-preset-target]')?.dataset.execPresetTarget || 'exec';
        if (target === 'eval') {
            const verdictInput = document.getElementById('eval-exec-verdict');
            const summaryInput = document.getElementById('eval-exec-summary');
            if (verdictInput) {
                verdictInput.value = verdict;
            }
            if (summaryInput) {
                summaryInput.value = summary;
            }
        } else {
            // Open the editor first (it reseeds from the live view), then apply the preset.
            if (execSummaryForm?.hidden) {
                setExecEditorOpen(true);
            }
            const verdictInput = document.getElementById('exec-verdict-input');
            const summaryInput = document.getElementById('exec-summary-input');
            if (verdictInput) {
                verdictInput.value = verdict;
            }
            if (summaryInput) {
                summaryInput.value = summary;
            }
        }
    };

    document.querySelectorAll('[data-exec-preset]').forEach((button) => {
        button.addEventListener('click', () => applyExecutivePreset(button));
    });

    const evaluationForm = document.getElementById('final-evaluation-form');
    if (evaluationForm) {
        const statusEl = document.getElementById('final-eval-status');
        const savedLabel = document.getElementById('final-eval-saved-label');
        const badge = document.getElementById('golive-status-badge');
        const execSummary = document.querySelector('.exec-summary');
        const saveBtn = document.getElementById('btn-save-evaluation');
        const readyCheckbox = document.getElementById('eval-ready');
        const notesField = document.getElementById('eval-notes');

        if (notesField) {
            notesField.addEventListener('input', () => {
                refreshGoliveGates();
            });
        }

        if (readyCheckbox) {
            readyCheckbox.addEventListener('change', () => {
                const gates = computeGoliveGatesClient();
                if (readyCheckbox.checked && !gates.ready_allowed) {
                    readyCheckbox.checked = false;
                    if (statusEl) {
                        statusEl.hidden = false;
                        statusEl.textContent = 'Complete all go-live gates before marking ready.';
                    }
                }
            });
        }

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
                    heroPill.textContent = '🚀 Ready to go-live';
                }
            } else if (heroPill) {
                heroPill.remove();
            }
            if (badge) {
                badge.hidden = false;
                badge.classList.toggle('is-pending', !ready);
                badge.textContent = `${ready ? '🚀 Ready to go-live' : '⏳ Not ready to go-live'}${name ? ` · ${name}` : ''}`;
            }
            if (savedLabel && evaluation.updated_at) {
                const by = evaluation.updated_by_label || formatActorLabel(evaluation) || '';
                savedLabel.textContent = by
                    ? `💾 Saved ${evaluation.updated_at} · ${by}`
                    : `💾 Saved ${evaluation.updated_at}`;
            }
            if (evaluation.updated_at || evaluation.updated_by_label) {
                prependHistoryEntry(document.getElementById('evaluation-history'), {
                    summary: `${ready ? 'Ready to go-live' : 'Not ready to go-live'} — evaluation saved`,
                    actor_username: evaluation.updated_by_username,
                    actor_display_name: evaluation.updated_by_display_name,
                    actor_auth_source: evaluation.updated_by_auth_source,
                    created_at: evaluation.updated_at,
                });
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
            const execVerdict = document.getElementById('eval-exec-verdict')?.value.trim() || '';
            const execSummary = document.getElementById('eval-exec-summary')?.value.trim() || '';
            const ready = !!document.getElementById('eval-ready')?.checked;
            const gates = computeGoliveGatesClient();

            if (ready && !gates.ready_allowed) {
                if (statusEl) {
                    statusEl.hidden = false;
                    statusEl.textContent = 'Complete all go-live gates before marking ready to go-live.';
                }
                return;
            }

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
                executive_verdict: execVerdict,
                executive_summary: execSummary,
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
                if (payload.executive) {
                    applyExecutiveSummaryUi(payload.executive);
                }
                if (payload.gates) {
                    applyGoliveGates(payload.gates);
                } else {
                    refreshGoliveGates();
                }
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

    if (params.get('tab') === 'actions' || window.location.hash === '#version-history' || window.location.hash === '#item-responses' || window.location.hash === '#final-evaluation') {
        activateTab('actions', false);
        let actionTab = params.get('action_tab') || 'risks';
        if (window.location.hash === '#version-history') {
            actionTab = 'versions';
        } else if (window.location.hash === '#final-evaluation') {
            actionTab = 'signoff';
        } else if (window.location.hash === '#item-responses') {
            actionTab = 'risks';
        }
        activateActionTab(actionTab, false);
        if (window.location.hash === '#version-history') {
            document.getElementById('version-history')?.scrollIntoView({ behavior: 'smooth' });
        }
        if (window.location.hash === '#final-evaluation') {
            document.getElementById('final-evaluation')?.scrollIntoView({ behavior: 'smooth' });
        }
        if (window.location.hash === '#item-responses') {
            document.getElementById('item-responses')?.scrollIntoView({ behavior: 'smooth' });
        }
    } else if (params.get('action_tab')) {
        activateTab('actions', false);
        activateActionTab(params.get('action_tab') || 'risks', false);
    }

    if (window.location.hash === '#final-evaluation' && params.get('tab') !== 'actions') {
        activateTab('actions', false);
        activateActionTab('signoff', false);
        document.getElementById('final-evaluation')?.scrollIntoView({ behavior: 'smooth' });
    }
});
