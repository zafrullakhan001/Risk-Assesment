document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const tabButtons = Array.from(document.querySelectorAll('.dash-tab'));
    const panels = Array.from(document.querySelectorAll('.dash-panel'));
    const assessmentId = document.body.dataset.assessmentId || '0';
    const csrfToken = document.body.dataset.csrfToken || '';
    const isReadOnly = document.body.dataset.readonly === '1';

    if (isReadOnly) {
        const originalFetch = window.fetch.bind(window);
        window.fetch = (input, init = {}) => {
            const url = typeof input === 'string' ? input : (input && input.url) || '';
            const method = String(init.method || (typeof input !== 'string' && input && input.method) || 'GET').toUpperCase();
            if (method !== 'GET' && method !== 'HEAD' && /index\.php/i.test(String(url))) {
                return Promise.reject(new Error('This shared view is read-only.'));
            }
            return originalFetch(input, init);
        };
    }

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
            const tabs = document.querySelector('.dash-tabs');
            if (tabs) {
                const topbarHeight = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--topbar-sticky-height')) || 0;
                const stickY = Math.max(0, tabs.offsetTop - topbarHeight);
                if (window.scrollY > stickY) {
                    window.scrollTo({ top: stickY, behavior: 'smooth' });
                }
            }
            window.requestAnimationFrame(() => window.syncStickyOffsets?.());
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
            const isPresentation = document.body.classList.contains('is-presentation');
            const label = isPresentation ? 'Exit presentation' : 'Presentation mode';
            presentationBtn.textContent = isPresentation ? '⏹' : '🎬';
            presentationBtn.setAttribute('title', label);
            presentationBtn.setAttribute('aria-label', label);
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
    const EXCEPTION_MAX_LINKS = 5;
    const EXCEPTION_STATUSES = ['Open', 'Approved', 'Closed', 'Expired'];

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

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const collectExceptionLinks = (root) => {
        const values = [];
        root.querySelectorAll('.exception-sn-link').forEach((input) => {
            const url = (input.value || '').trim();
            if (!url || values.includes(url)) {
                return;
            }
            values.push(url);
        });
        return values.slice(0, EXCEPTION_MAX_LINKS);
    };

    const refreshExceptionSnUi = (block) => {
        if (!block) {
            return;
        }
        const rows = block.querySelectorAll('.exception-sn-row');
        const count = rows.length;
        const countLabel = block.querySelector('.exception-sn-count');
        if (countLabel) {
            countLabel.textContent = `${count} / ${EXCEPTION_MAX_LINKS}`;
        }
        const addBtn = block.querySelector('.exception-sn-add');
        if (addBtn) {
            addBtn.hidden = count >= EXCEPTION_MAX_LINKS;
        }
        rows.forEach((row) => {
            const input = row.querySelector('.exception-sn-link');
            const open = row.querySelector('.exception-sn-open');
            const url = (input?.value || '').trim();
            if (open) {
                if (url) {
                    open.href = url;
                    open.hidden = false;
                } else {
                    open.hidden = true;
                }
            }
        });
    };

    const createExceptionSnRow = (url = '') => {
        const wrap = document.createElement('div');
        wrap.className = 'exception-sn-row';
        wrap.innerHTML = `
            <input type="url" class="exception-sn-link" maxlength="2000" placeholder="https://…service-now.com/…" value="${escapeHtml(url)}">
            <a class="exception-sn-open" href="${escapeHtml(url || '#')}" target="_blank" rel="noopener noreferrer" title="Open link" ${url ? '' : 'hidden'}>↗</a>
            <button type="button" class="button button-secondary exception-sn-remove" title="Remove link" aria-label="Remove link">✕</button>
        `;
        return wrap;
    };

    const parseExceptionLinks = (raw) => {
        if (Array.isArray(raw)) {
            return raw.filter((url) => typeof url === 'string' && url.trim() !== '').slice(0, EXCEPTION_MAX_LINKS);
        }
        if (typeof raw !== 'string' || raw.trim() === '') {
            return [];
        }
        try {
            const decoded = JSON.parse(raw);
            return Array.isArray(decoded)
                ? decoded.filter((url) => typeof url === 'string' && url.trim() !== '').slice(0, EXCEPTION_MAX_LINKS)
                : [];
        } catch (error) {
            return [];
        }
    };

    const commentPreviewText = (comment) => {
        const text = String(comment || '').trim();
        if (!text) {
            return '';
        }
        return text.length > 90 ? `${text.slice(0, 87)}…` : text;
    };

    const updateExceptionRowPreview = (row, comment, links) => {
        if (!row) {
            return;
        }
        row.dataset.comment = comment || '';
        row.dataset.servicenowLinks = JSON.stringify(Array.isArray(links) ? links : []);
        const preview = row.querySelector('.exception-comment-preview');
        if (preview) {
            const short = commentPreviewText(comment);
            preview.textContent = short || 'No comments yet';
            preview.classList.toggle('is-empty', !short);
        }
        const linkPreview = row.querySelector('.exception-link-preview');
        if (linkPreview) {
            const count = Array.isArray(links) ? links.length : 0;
            linkPreview.textContent = count === 0
                ? 'No ServiceNow links'
                : `${count} ServiceNow link${count === 1 ? '' : 's'}`;
            linkPreview.classList.toggle('is-empty', count === 0);
        }
    };

    const buildExceptionRowHtml = (finding) => {
        const id = escapeHtml(finding.id || '');
        const status = EXCEPTION_STATUSES.includes(finding.status) ? finding.status : 'Open';
        const links = Array.isArray(finding.servicenow_links) ? finding.servicenow_links.slice(0, EXCEPTION_MAX_LINKS) : [];
        const comment = finding.comment || '';
        const preview = commentPreviewText(comment);
        const statusOptions = EXCEPTION_STATUSES.map((option) => (
            `<option value="${option}" ${option === status ? 'selected' : ''}>${option}</option>`
        )).join('');
        const policy = finding.policy_reference
            ? `<div class="subtext">Policy: ${escapeHtml(finding.policy_reference)}</div>`
            : '';
        const mitigation = finding.mitigation
            ? `<div class="subtext clamp-text" data-expandable>${escapeHtml(finding.mitigation)}</div>`
            : '';

        return `
            <tr
                data-finding-id="${id}"
                class="exception-row"
                data-finding-text="${escapeHtml(finding.finding || '')}"
                data-policy="${escapeHtml(finding.policy_reference || '')}"
                data-owner="${escapeHtml(finding.owner || '')}"
                data-timeline="${escapeHtml(finding.timeline || '')}"
                data-mitigation="${escapeHtml(finding.mitigation || '')}"
                data-impact="${escapeHtml(finding.impact || '')}"
                data-comment="${escapeHtml(comment)}"
                data-servicenow-links="${escapeHtml(JSON.stringify(links))}"
            >
                <td>
                    <div class="exception-status-wrap">
                        <select class="exception-status" data-finding-id="${id}" aria-label="Exception status">${statusOptions}</select>
                        <span class="exception-status-save" hidden></span>
                    </div>
                </td>
                <td class="exception-finding-cell">
                    <div class="clamp-text" data-expandable>${escapeHtml(finding.finding || '')}</div>
                    ${policy}
                    ${mitigation}
                </td>
                <td class="exception-notes-preview-cell">
                    <div class="exception-notes-preview">
                        <p class="exception-comment-preview ${preview ? '' : 'is-empty'}">${escapeHtml(preview || 'No comments yet')}</p>
                        <span class="exception-link-preview ${links.length ? '' : 'is-empty'}">${
                            links.length === 0
                                ? 'No ServiceNow links'
                                : `${links.length} ServiceNow link${links.length === 1 ? '' : 's'}`
                        }</span>
                    </div>
                </td>
                <td>${escapeHtml(finding.owner || '')}</td>
                <td>${escapeHtml(finding.timeline || '')}</td>
                <td class="col-actions">
                    <div class="exception-row-actions">
                        <button type="button" class="button button-secondary exception-edit-row" data-finding-id="${id}" title="Edit exception details" aria-label="Edit exception details">✏️</button>
                        <button type="button" class="button button-secondary exception-delete-row" data-finding-id="${id}" title="Delete exception" aria-label="Delete exception">🗑️</button>
                    </div>
                </td>
            </tr>
        `;
    };

    const updateExceptionTabCount = () => {
        const count = document.querySelectorAll('#exception-table tbody tr.exception-row').length;
        const tab = document.querySelector('.action-tab[data-action-tab="exceptions"] em');
        if (tab) {
            tab.textContent = String(count);
        }
        const empty = document.getElementById('exception-empty');
        const wrap = document.getElementById('exception-table-wrap');
        if (empty) {
            empty.hidden = count > 0;
        }
        if (wrap) {
            wrap.hidden = count === 0;
        }
    };

    const updateExceptionOpenCount = () => {
        const openCount = Array.from(document.querySelectorAll('.exception-status'))
            .filter((node) => node.value === 'Open').length;
        const countLabel = document.getElementById('exception-open-count');
        if (countLabel) {
            countLabel.textContent = `${openCount} open`;
        }
        return openCount;
    };

    const persistFindingStatus = async (findingId, status, extras = null) => {
        const body = new URLSearchParams({
            action: 'save_finding_status',
            csrf_token: csrfToken,
            assessment_id: String(assessmentId),
            finding_id: findingId,
            status,
        });
        if (extras && typeof extras.comment === 'string') {
            body.set('comment', extras.comment);
        }
        if (extras && Array.isArray(extras.servicenow_links)) {
            body.set('servicenow_links', JSON.stringify(extras.servicenow_links));
        }

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

    const bindExceptionStatusSelect = (select) => {
        const findingId = select.dataset.findingId;
        if (!findingId || select.dataset.bound === '1') {
            return;
        }
        select.dataset.bound = '1';
        if (Number(assessmentId) <= 0 && savedExceptions[findingId]) {
            const saved = savedExceptions[findingId];
            select.value = typeof saved === 'string' ? saved : (saved.status || select.value);
        }
        let saveGeneration = 0;
        select.addEventListener('change', async () => {
            updateExceptionOpenCount();
            updateExecSummaryFromExceptions();

            const generation = ++saveGeneration;
            const status = select.value;
            const row = select.closest('tr.exception-row');

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
                    updateExceptionOpenCount();
                }
                return;
            }

            savedExceptions[findingId] = {
                ...(typeof savedExceptions[findingId] === 'object' ? savedExceptions[findingId] : {}),
                status,
                comment: row?.dataset.comment || '',
                servicenow_links: parseExceptionLinks(row?.dataset.servicenowLinks || '[]'),
            };
            localStorage.setItem(exceptionKey, JSON.stringify(savedExceptions));
            setExceptionSaveLabel(select, 'Saved locally');
            window.setTimeout(() => {
                if (generation === saveGeneration) {
                    setExceptionSaveLabel(select, '');
                }
            }, 1200);
            refreshGoliveGates();
        });
    };

    const bindExceptionRow = (row) => {
        if (!row || row.dataset.exceptionBound === '1') {
            return;
        }
        row.dataset.exceptionBound = '1';

        const select = row.querySelector('.exception-status');
        if (select) {
            bindExceptionStatusSelect(select);
        }

        const findingId = row.dataset.findingId || '';
        if (Number(assessmentId) <= 0 && savedExceptions[findingId] && typeof savedExceptions[findingId] === 'object') {
            const comment = savedExceptions[findingId].comment || '';
            const links = Array.isArray(savedExceptions[findingId].servicenow_links)
                ? savedExceptions[findingId].servicenow_links
                : [];
            updateExceptionRowPreview(row, comment, links);
        }
    };

    document.querySelectorAll('#exception-table tbody tr.exception-row').forEach(bindExceptionRow);

    const exceptionEditDialog = document.getElementById('exception-edit-dialog');
    const exceptionEditForm = document.getElementById('exception-edit-form');
    const exceptionEditStatus = document.getElementById('exception-edit-status');
    const exceptionEditComment = document.getElementById('exception-edit-comment');
    const exceptionEditSnBlock = document.getElementById('exception-edit-sn-block');
    const exceptionEditSnList = document.getElementById('exception-edit-sn-list');
    const exceptionEditStatusMsg = document.getElementById('exception-edit-status-msg');
    const exceptionEditSave = document.getElementById('exception-edit-save');
    let exceptionEditRow = null;

    if (exceptionEditDialog && exceptionEditDialog.parentElement !== document.body) {
        document.body.appendChild(exceptionEditDialog);
    }

    const setExceptionEditStatus = (message, isError = false) => {
        if (!exceptionEditStatusMsg) {
            return;
        }
        exceptionEditStatusMsg.hidden = !message;
        exceptionEditStatusMsg.textContent = message || '';
        exceptionEditStatusMsg.classList.toggle('is-error', !!isError);
    };

    const setExceptionEditDetail = (key, value) => {
        const chip = exceptionEditDialog?.querySelector(`[data-exception-detail="${key}"]`);
        const text = String(value || '').trim();
        if (!chip) {
            return false;
        }
        const valueEl = chip.querySelector('.response-dialog-detail-value, .response-dialog-detail-text');
        if (valueEl) {
            valueEl.textContent = text;
        }
        chip.hidden = text === '';
        return text !== '';
    };

    const fillExceptionEditSnList = (links) => {
        if (!exceptionEditSnList) {
            return;
        }
        exceptionEditSnList.innerHTML = '';
        const items = Array.isArray(links) && links.length ? links : [''];
        items.slice(0, EXCEPTION_MAX_LINKS).forEach((url) => {
            exceptionEditSnList.appendChild(createExceptionSnRow(url));
        });
        refreshExceptionSnUi(exceptionEditSnBlock);
    };

    const closeExceptionEditDialog = () => {
        if (exceptionEditDialog && typeof exceptionEditDialog.close === 'function' && exceptionEditDialog.open) {
            exceptionEditDialog.close();
        }
        exceptionEditRow = null;
        setExceptionEditStatus('');
    };

    const openExceptionEditDialog = (row) => {
        if (!exceptionEditDialog || !row) {
            return;
        }
        exceptionEditRow = row;
        const findingText = row.dataset.findingText || '';
        const title = document.getElementById('exception-edit-title');
        const sub = document.getElementById('exception-edit-sub');
        if (title) {
            title.textContent = findingText
                ? (findingText.length > 80 ? `${findingText.slice(0, 77)}…` : findingText)
                : 'Edit exception';
        }
        if (sub) {
            const bits = [row.dataset.owner, row.dataset.timeline].filter(Boolean);
            sub.textContent = bits.join(' · ');
            sub.hidden = bits.length === 0;
        }

        setExceptionEditDetail('finding', findingText);
        setExceptionEditDetail('policy', row.dataset.policy || '');
        setExceptionEditDetail('owner', row.dataset.owner || '');
        setExceptionEditDetail('timeline', row.dataset.timeline || '');
        setExceptionEditDetail('mitigation', row.dataset.mitigation || '');
        setExceptionEditDetail('impact', row.dataset.impact || '');

        if (exceptionEditStatus) {
            exceptionEditStatus.value = row.querySelector('.exception-status')?.value || 'Open';
        }
        if (exceptionEditComment) {
            exceptionEditComment.value = row.dataset.comment || '';
        }
        fillExceptionEditSnList(parseExceptionLinks(row.dataset.servicenowLinks || '[]'));
        setExceptionEditStatus('');

        if (typeof exceptionEditDialog.showModal === 'function') {
            exceptionEditDialog.showModal();
        } else {
            exceptionEditDialog.setAttribute('open', 'open');
        }
        exceptionEditStatus?.focus();
    };

    exceptionEditDialog?.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        if (target.closest('#exception-edit-sn-add')) {
            if (!exceptionEditSnList || exceptionEditSnList.querySelectorAll('.exception-sn-row').length >= EXCEPTION_MAX_LINKS) {
                return;
            }
            exceptionEditSnList.appendChild(createExceptionSnRow(''));
            refreshExceptionSnUi(exceptionEditSnBlock);
            exceptionEditSnList.querySelector('.exception-sn-row:last-child .exception-sn-link')?.focus();
            return;
        }
        if (target.closest('.exception-sn-remove')) {
            const rowEl = target.closest('.exception-sn-row');
            if (!rowEl || !exceptionEditSnList) {
                return;
            }
            rowEl.remove();
            if (exceptionEditSnList.querySelectorAll('.exception-sn-row').length === 0) {
                exceptionEditSnList.appendChild(createExceptionSnRow(''));
            }
            refreshExceptionSnUi(exceptionEditSnBlock);
        }
    });

    exceptionEditDialog?.addEventListener('input', (event) => {
        const target = event.target;
        if (target instanceof Element && target.classList.contains('exception-sn-link')) {
            refreshExceptionSnUi(exceptionEditSnBlock);
        }
    });

    document.getElementById('exception-edit-close')?.addEventListener('click', closeExceptionEditDialog);
    document.getElementById('exception-edit-cancel')?.addEventListener('click', closeExceptionEditDialog);
    exceptionEditDialog?.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeExceptionEditDialog();
    });

    exceptionEditForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const row = exceptionEditRow;
        const findingId = row?.dataset.findingId || '';
        if (!row || !findingId) {
            return;
        }
        const status = exceptionEditStatus?.value || 'Open';
        const comment = exceptionEditComment?.value || '';
        const links = collectExceptionLinks(exceptionEditSnBlock || exceptionEditDialog);
        setExceptionEditStatus('Saving…');
        if (exceptionEditSave) {
            exceptionEditSave.disabled = true;
        }
        try {
            if (Number(assessmentId) > 0) {
                const payload = await persistFindingStatus(findingId, status, {
                    comment,
                    servicenow_links: links,
                });
                const savedComment = typeof payload.comment === 'string' ? payload.comment : comment;
                const savedLinks = Array.isArray(payload.servicenow_links) ? payload.servicenow_links : links;
                const select = row.querySelector('.exception-status');
                if (select && payload.status) {
                    select.value = payload.status;
                } else if (select) {
                    select.value = status;
                }
                updateExceptionRowPreview(row, savedComment, savedLinks);
                updateExceptionOpenCount();
                updateExecSummaryFromExceptions();
                if (payload.gates) {
                    applyGoliveGates(payload.gates);
                } else {
                    refreshGoliveGates();
                }
            } else {
                const select = row.querySelector('.exception-status');
                if (select) {
                    select.value = status;
                }
                updateExceptionRowPreview(row, comment, links);
                savedExceptions[findingId] = {
                    status,
                    comment,
                    servicenow_links: links,
                };
                localStorage.setItem(exceptionKey, JSON.stringify(savedExceptions));
                updateExceptionOpenCount();
                updateExecSummaryFromExceptions();
                refreshGoliveGates();
            }
            closeExceptionEditDialog();
        } catch (error) {
            setExceptionEditStatus(error.message || 'Save failed', true);
        } finally {
            if (exceptionEditSave) {
                exceptionEditSave.disabled = false;
            }
        }
    });

    const exceptionTracker = document.getElementById('exception-tracker');
    exceptionTracker?.addEventListener('click', async (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        const editBtn = target.closest('.exception-edit-row');
        if (editBtn) {
            const row = editBtn.closest('tr.exception-row');
            if (row) {
                openExceptionEditDialog(row);
            }
            return;
        }

        const deleteBtn = target.closest('.exception-delete-row');
        if (deleteBtn) {
            const row = deleteBtn.closest('tr.exception-row');
            const findingId = row?.dataset.findingId || deleteBtn.dataset.findingId || '';
            if (!row || !findingId) {
                return;
            }
            if (!window.confirm('Delete this exception row? This cannot be undone.')) {
                return;
            }
            if (Number(assessmentId) <= 0) {
                row.remove();
                delete savedExceptions[findingId];
                localStorage.setItem(exceptionKey, JSON.stringify(savedExceptions));
                updateExceptionTabCount();
                updateExceptionOpenCount();
                updateExecSummaryFromExceptions();
                refreshGoliveGates();
                return;
            }
            deleteBtn.disabled = true;
            try {
                const body = new URLSearchParams({
                    action: 'delete_finding',
                    csrf_token: csrfToken,
                    assessment_id: String(assessmentId),
                    finding_id: findingId,
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
                    throw new Error('Delete failed');
                }
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || 'Delete failed');
                }
                row.remove();
                updateExceptionTabCount();
                updateExceptionOpenCount();
                updateExecSummaryFromExceptions();
                if (payload.gates) {
                    applyGoliveGates(payload.gates);
                } else {
                    refreshGoliveGates();
                }
            } catch (error) {
                window.alert(error.message || 'Delete failed');
                deleteBtn.disabled = false;
            }
        }
    });

    const exceptionAddDialog = document.getElementById('exception-add-dialog');
    const exceptionAddForm = document.getElementById('exception-add-form');
    const exceptionAddStatus = document.getElementById('exception-add-status');
    const exceptionAddSave = document.getElementById('exception-add-save');
    const exceptionAddBtn = document.getElementById('exception-add-row');

    if (exceptionAddDialog && exceptionAddDialog.parentElement !== document.body) {
        document.body.appendChild(exceptionAddDialog);
    }

    const setExceptionAddStatus = (message, isError = false) => {
        if (!exceptionAddStatus) {
            return;
        }
        exceptionAddStatus.hidden = !message;
        exceptionAddStatus.textContent = message || '';
        exceptionAddStatus.classList.toggle('is-error', !!isError);
    };

    const closeExceptionAddDialog = () => {
        if (exceptionAddDialog && typeof exceptionAddDialog.close === 'function' && exceptionAddDialog.open) {
            exceptionAddDialog.close();
        }
        setExceptionAddStatus('');
    };

    const openExceptionAddDialog = () => {
        if (!exceptionAddDialog || Number(assessmentId) <= 0) {
            return;
        }
        exceptionAddForm?.reset();
        setExceptionAddStatus('');
        if (typeof exceptionAddDialog.showModal === 'function') {
            exceptionAddDialog.showModal();
        } else {
            exceptionAddDialog.setAttribute('open', 'open');
        }
        document.getElementById('exception-add-finding')?.focus();
    };

    exceptionAddBtn?.addEventListener('click', openExceptionAddDialog);

    exceptionAddSave?.addEventListener('click', async () => {
        if (Number(assessmentId) <= 0) {
            return;
        }
        const finding = document.getElementById('exception-add-finding')?.value.trim() || '';
        if (!finding) {
            setExceptionAddStatus('Finding text is required.', true);
            document.getElementById('exception-add-finding')?.focus();
            return;
        }
        exceptionAddSave.disabled = true;
        setExceptionAddStatus('Saving…');
        try {
            const body = new URLSearchParams({
                action: 'add_finding',
                csrf_token: csrfToken,
                assessment_id: String(assessmentId),
                finding,
                policy_reference: document.getElementById('exception-add-policy')?.value || '',
                impact: document.getElementById('exception-add-impact')?.value || '',
                mitigation: document.getElementById('exception-add-mitigation')?.value || '',
                owner: document.getElementById('exception-add-owner')?.value || '',
                timeline: document.getElementById('exception-add-timeline')?.value || '',
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
                throw new Error('Add failed');
            }
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Add failed');
            }

            const tbody = document.querySelector('#exception-table tbody');
            if (tbody && payload.finding) {
                tbody.insertAdjacentHTML('beforeend', buildExceptionRowHtml(payload.finding));
                const newRow = tbody.querySelector(`tr[data-finding-id="${CSS.escape(payload.finding.id)}"]`);
                if (newRow) {
                    bindExceptionRow(newRow);
                }
            }
            updateExceptionTabCount();
            updateExceptionOpenCount();
            updateExecSummaryFromExceptions();
            if (payload.gates) {
                applyGoliveGates(payload.gates);
            } else {
                refreshGoliveGates();
            }
            closeExceptionAddDialog();
        } catch (error) {
            setExceptionAddStatus(error.message || 'Add failed', true);
        } finally {
            exceptionAddSave.disabled = false;
        }
    });

    updateExceptionTabCount();
    updateExceptionOpenCount();
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

    const responseActionLabels = {
        open: 'Open',
        take_care: 'Taken care',
        ignore: 'Ignore',
        not_applicable: 'Not applicable',
        closed: 'Closed',
    };

    const truncateComment = (comment, max = 90) => {
        const text = String(comment || '').trim();
        if (text === '') {
            return '';
        }
        return text.length > max ? `${text.slice(0, max - 1)}…` : text;
    };

    const refreshResponseSummary = (widget) => {
        if (!widget) {
            return;
        }
        const action = widget.querySelector('.item-response-action')?.value || 'open';
        const comment = widget.querySelector('.item-response-comment')?.value || '';
        const pill = widget.querySelector('.response-pill');
        const preview = widget.querySelector('.item-response-comment-preview');
        if (pill) {
            pill.textContent = responseActionLabels[action] || 'Open';
            pill.className = `response-pill response-${action}`;
        }
        if (preview) {
            const short = truncateComment(comment);
            preview.textContent = short || 'No comment yet';
            preview.classList.toggle('is-empty', short === '');
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
            refreshResponseSummary(widget);
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
                    pill.textContent = responseActionLabels[action] || 'Open';
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
            applyItemAttribution(sourceWidget, payload);
            refreshResponseSummary(sourceWidget);
            refreshGoliveGates();
        } catch (error) {
            if (saveLabel) {
                saveLabel.hidden = false;
                saveLabel.textContent = 'Save failed';
            }
            throw error;
        }
    };

    const actionLabelsMap = {
        open: 'Open',
        take_care: 'Taken care',
        ignore: 'Ignore',
        not_applicable: 'Not applicable',
        closed: 'Closed',
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

    const historyPostBody = (entry = {}) => {
        const details = entry.details && typeof entry.details === 'object' ? entry.details : {};
        const comment = String(details.comment || details.notes || '').trim();
        const actionKey = String(details.action || '').trim();
        const actionLabel = String(details.action_label || actionLabelsMap[actionKey] || '').trim();
        const title = String(entry.summary || actionLabel || 'Update').trim();
        return { title, comment, actionKey, actionLabel };
    };

    const renderHistoryPost = (entry = {}) => {
        const { title, comment, actionKey, actionLabel } = historyPostBody(entry);
        const article = document.createElement('article');
        article.className = 'history-post';
        if (actionKey) {
            article.dataset.action = actionKey;
        }

        const head = document.createElement('header');
        head.className = 'history-post-head';
        const heading = document.createElement('h5');
        heading.className = 'history-post-title';
        heading.textContent = title;
        head.appendChild(heading);
        if (actionLabel) {
            const pill = document.createElement('span');
            pill.className = actionKey
                ? `response-pill response-${actionKey}`
                : 'history-post-status';
            pill.textContent = actionLabel;
            head.appendChild(pill);
        }
        article.appendChild(head);

        const byline = document.createElement('p');
        byline.className = 'history-post-byline';
        const actorLabel = formatActorLabel(entry) || 'Unknown user';
        byline.textContent = `${actorLabel}${entry.created_at ? ` · ${entry.created_at}` : ''}`;
        article.appendChild(byline);

        const body = document.createElement('div');
        body.className = 'history-post-body';
        if (comment !== '') {
            body.textContent = comment;
        } else {
            body.classList.add('is-empty');
            body.textContent = 'No comment was recorded with this update.';
        }
        article.appendChild(body);

        return article;
    };

    const createHistoryPanelController = (panel) => {
        if (!panel) {
            return null;
        }
        const listEl = panel.querySelector('.history-panel-list');
        const emptyEl = panel.querySelector('.history-panel-empty');
        const metaEl = panel.querySelector('.history-panel-meta');
        const countEl = panel.querySelector('.history-panel-count');
        const searchEl = panel.querySelector('.history-panel-search');
        const paginationEl = panel.querySelector('.history-panel-pagination');
        const pageEl = panel.querySelector('.history-panel-page');
        const prevBtn = panel.querySelector('.history-panel-prev');
        const nextBtn = panel.querySelector('.history-panel-next');
        const perPageSelect = panel.querySelector('.history-panel-per-page-select');
        const allowedPerPage = [5, 10, 20];
        const historyPerPageKey = 'ra-history-per-page';
        const readStoredPerPage = () => {
            try {
                const stored = parseInt(window.localStorage.getItem(historyPerPageKey) || '', 10);
                return allowedPerPage.includes(stored) ? stored : null;
            } catch (error) {
                return null;
            }
        };
        const writeStoredPerPage = (value) => {
            try {
                window.localStorage.setItem(historyPerPageKey, String(value));
            } catch (error) {
                // Ignore quota / private-mode failures.
            }
        };
        const initialPerPage = readStoredPerPage()
            ?? Math.max(1, parseInt(panel.dataset.perPage || '5', 10) || 5);
        const state = {
            page: 1,
            query: '',
            perPage: allowedPerPage.includes(initialPerPage) ? initialPerPage : 5,
            total: 0,
            totalPages: 1,
            loading: false,
        };
        let searchTimer = null;

        panel.dataset.perPage = String(state.perPage);
        if (perPageSelect) {
            perPageSelect.value = String(state.perPage);
        }

        const updatePager = () => {
            if (!paginationEl) {
                return;
            }
            const showPager = state.totalPages > 1;
            paginationEl.hidden = !showPager;
            if (pageEl) {
                pageEl.textContent = `Page ${state.page} / ${state.totalPages}`;
            }
            if (prevBtn) {
                prevBtn.disabled = state.page <= 1 || state.loading;
            }
            if (nextBtn) {
                nextBtn.disabled = state.page >= state.totalPages || state.loading;
            }
            if (perPageSelect) {
                perPageSelect.disabled = state.loading;
            }
        };

        const setLoading = (loading) => {
            state.loading = loading;
            panel.classList.toggle('is-loading', loading);
            updatePager();
        };

        const renderEntries = (entries) => {
            if (!listEl) {
                return;
            }
            listEl.innerHTML = '';
            entries.forEach((entry) => {
                listEl.appendChild(renderHistoryPost(entry));
            });
            listEl.scrollTop = 0;
            if (emptyEl) {
                emptyEl.hidden = entries.length > 0 || state.total > 0;
                if (state.total === 0) {
                    emptyEl.textContent = state.query
                        ? 'No history posts match that search.'
                        : (panel.dataset.emptyText || 'No history posts yet.');
                    emptyEl.hidden = false;
                } else if (entries.length === 0) {
                    emptyEl.hidden = false;
                    emptyEl.textContent = 'No history posts on this page.';
                } else {
                    emptyEl.hidden = true;
                }
            }
            if (countEl) {
                countEl.hidden = state.total <= 0;
                countEl.textContent = String(state.total);
            }
            if (metaEl) {
                if (state.total === 0) {
                    metaEl.textContent = '';
                } else {
                    const from = ((state.page - 1) * state.perPage) + 1;
                    const to = Math.min(state.total, state.page * state.perPage);
                    metaEl.textContent = `Showing ${from}–${to} of ${state.total}`;
                }
            }
            updatePager();
            panel.hidden = false;
        };

        const load = async (page = state.page) => {
            const assessmentIdValue = Number(panel.dataset.assessmentId || assessmentId || 0);
            const entityType = panel.dataset.entityType || '';
            if (assessmentIdValue <= 0 || !entityType) {
                renderEntries([]);
                return;
            }
            setLoading(true);
            try {
                const body = new URLSearchParams({
                    action: 'list_change_history',
                    csrf_token: csrfToken,
                    assessment_id: String(assessmentIdValue),
                    entity_type: entityType,
                    entity_key: panel.dataset.entityKey || '',
                    q: state.query,
                    page: String(page),
                    per_page: String(state.perPage),
                });
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body,
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || 'Unable to load history');
                }
                state.page = Number(payload.page || 1);
                state.total = Number(payload.total || 0);
                state.totalPages = Number(payload.total_pages || 1);
                state.perPage = Number(payload.per_page || state.perPage);
                if (perPageSelect) {
                    perPageSelect.value = String(state.perPage);
                }
                renderEntries(Array.isArray(payload.entries) ? payload.entries : []);
            } catch (error) {
                if (listEl) {
                    listEl.innerHTML = '';
                }
                if (emptyEl) {
                    emptyEl.hidden = false;
                    emptyEl.textContent = error?.message || 'Unable to load history.';
                }
            } finally {
                setLoading(false);
            }
        };

        searchEl?.addEventListener('input', () => {
            if (searchTimer) {
                window.clearTimeout(searchTimer);
            }
            searchTimer = window.setTimeout(() => {
                state.query = searchEl.value.trim();
                state.page = 1;
                load(1);
            }, 280);
        });
        prevBtn?.addEventListener('click', () => {
            if (state.page > 1) {
                load(state.page - 1);
            }
        });
        nextBtn?.addEventListener('click', () => {
            if (state.page < state.totalPages) {
                load(state.page + 1);
            }
        });
        perPageSelect?.addEventListener('change', () => {
            const nextPerPage = parseInt(perPageSelect.value, 10);
            if (!allowedPerPage.includes(nextPerPage) || nextPerPage === state.perPage) {
                perPageSelect.value = String(state.perPage);
                return;
            }
            state.perPage = nextPerPage;
            panel.dataset.perPage = String(nextPerPage);
            writeStoredPerPage(nextPerPage);
            state.page = 1;
            load(1);
        });

        return {
            panel,
            load,
            reload: () => load(1),
            setEntityKey(entityKey) {
                panel.dataset.entityKey = entityKey || '';
            },
            setAssessmentId(id) {
                panel.dataset.assessmentId = String(id || 0);
            },
            clearSearch() {
                state.query = '';
                state.page = 1;
                if (searchEl) {
                    searchEl.value = '';
                }
            },
        };
    };

    const historyControllers = new Map();
    document.querySelectorAll('[data-history-panel]').forEach((panel) => {
        const controller = createHistoryPanelController(panel);
        if (controller) {
            historyControllers.set(panel.id || panel, controller);
            if (panel.id === 'evaluation-history') {
                controller.load(1);
            }
        }
    });

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
        refreshResponseSummary(widget);
    };

    document.querySelectorAll('.item-response').forEach((widget) => {
        const itemKey = widget.dataset.itemKey;
        if (!itemKey) {
            return;
        }
        const actionSelect = widget.querySelector('.item-response-action');
        const commentField = widget.querySelector('.item-response-comment');
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
        } else {
            refreshResponseSummary(widget);
        }
    });

    const responseDialog = document.getElementById('item-response-dialog');
    const responseDialogForm = document.getElementById('item-response-dialog-form');
    const responseDialogAction = document.getElementById('response-dialog-action');
    const responseDialogComment = document.getElementById('response-dialog-comment');
    const responseDialogTitle = document.getElementById('response-dialog-title');
    const responseDialogSub = document.getElementById('response-dialog-sub');
    const responseDialogAttribution = document.getElementById('response-dialog-attribution');
    const responseDialogHistory = document.getElementById('response-dialog-history');
    const responseDialogStatus = document.getElementById('response-dialog-status');
    const responseDialogSave = document.getElementById('response-dialog-save');
    const responseDialogDetails = document.getElementById('response-dialog-details');
    let responseDialogWidget = null;

    // Keep the dialog outside tab panels — Actions panel is display:none when
    // Architecture / Due diligence is active, which would hide showModal().
    if (responseDialog && responseDialog.parentElement !== document.body) {
        document.body.appendChild(responseDialog);
    }

    const setResponseDialogStatus = (message, isError = false) => {
        if (!responseDialogStatus) {
            return;
        }
        responseDialogStatus.hidden = !message;
        responseDialogStatus.textContent = message || '';
        responseDialogStatus.classList.toggle('is-error', !!isError);
    };

    const fillResponseDialogDetail = (key, value, { tone = '', display = null } = {}) => {
        if (!responseDialogDetails) {
            return false;
        }
        const chipOrBlock = responseDialogDetails.querySelector(`[data-detail="${key}"]`);
        const valueEl = document.getElementById(`response-dialog-detail-${key}`);
        const text = (value || '').trim();
        if (chipOrBlock) {
            [...chipOrBlock.classList].forEach((cls) => {
                if (cls.startsWith('tone-')) {
                    chipOrBlock.classList.remove(cls);
                }
            });
            chipOrBlock.hidden = text === '';
            chipOrBlock.classList.toggle('is-empty', text === '');
            if (tone && text !== '') {
                chipOrBlock.classList.add(`tone-${tone}`);
            }
        }
        if (valueEl) {
            valueEl.textContent = text === '' ? '' : (display ?? text);
        }
        return text !== '';
    };

    const statusDialogMeta = (status) => {
        const key = String(status || '').trim().toLowerCase();
        const map = {
            pass: { emoji: '✅', tone: 'pass' },
            gap: { emoji: '🟠', tone: 'gap' },
            risk: { emoji: '🔴', tone: 'risk' },
            tbd: { emoji: '❓', tone: 'tbd' },
            'n/a': { emoji: '➖', tone: 'na' },
            na: { emoji: '➖', tone: 'na' },
        };
        return map[key] || { emoji: '📌', tone: 'neutral' };
    };

    const riskDialogMeta = (risk) => {
        const key = String(risk || '').trim().toLowerCase();
        const map = {
            high: { emoji: '🔴', tone: 'risk-high' },
            med: { emoji: '🟡', tone: 'risk-med' },
            medium: { emoji: '🟡', tone: 'risk-med' },
            low: { emoji: '🟢', tone: 'risk-low' },
        };
        return map[key] || { emoji: '⚪', tone: 'neutral' };
    };

    const populateResponseDialogDetails = (widget) => {
        if (!responseDialogDetails || !widget) {
            return;
        }
        const source = (widget.dataset.itemSource || '').trim();
        const section = (widget.dataset.itemSection || '').trim();
        const status = (widget.dataset.itemStatus || '').trim();
        const risk = (widget.dataset.itemRisk || '').trim();
        const owner = (widget.dataset.itemOwner || '').trim();
        const timeline = (widget.dataset.itemTimeline || '').trim();
        const notes = (widget.dataset.itemNotes || '').trim();
        const mitigation = (widget.dataset.itemMitigation || '').trim();
        const reviewQuestion = (widget.dataset.itemReviewQuestion || '').trim();
        const sourceRef = (widget.dataset.itemSourceRef || '').trim();
        const statusMeta = statusDialogMeta(status);
        const riskMeta = riskDialogMeta(risk);
        const sourceIcon = source.toLowerCase().includes('due') ? '🔍' : '🏛️';

        const detailsIcon = document.getElementById('response-dialog-details-icon');
        if (detailsIcon) {
            detailsIcon.textContent = sourceIcon;
        }

        responseDialogDetails.className = 'response-dialog-details';
        if (riskMeta.tone !== 'neutral') {
            responseDialogDetails.classList.add(`accent-${riskMeta.tone}`);
        } else if (statusMeta.tone !== 'neutral') {
            responseDialogDetails.classList.add(`accent-status-${statusMeta.tone}`);
        }

        const hasAny = [
            fillResponseDialogDetail('source', source, {
                tone: source.toLowerCase().includes('due') ? 'diligence' : 'architecture',
                display: `${sourceIcon} ${source}`,
            }),
            fillResponseDialogDetail('section', section, { tone: 'section' }),
            fillResponseDialogDetail('status', status, {
                tone: statusMeta.tone,
                display: `${statusMeta.emoji} ${status}`,
            }),
            fillResponseDialogDetail('risk', risk, {
                tone: riskMeta.tone,
                display: `${riskMeta.emoji} ${risk}`,
            }),
            fillResponseDialogDetail('owner', owner, { tone: 'owner' }),
            fillResponseDialogDetail('timeline', timeline, { tone: 'timeline' }),
            fillResponseDialogDetail('notes', notes, { tone: 'notes' }),
            fillResponseDialogDetail('mitigation', mitigation, { tone: 'mitigation' }),
            fillResponseDialogDetail('review-question', reviewQuestion, { tone: 'question' }),
            fillResponseDialogDetail('source-ref', sourceRef, { tone: 'reference' }),
        ].some(Boolean);
        responseDialogDetails.hidden = !hasAny;
    };

    const closeResponseDialog = () => {
        if (responseDialog && typeof responseDialog.close === 'function' && responseDialog.open) {
            responseDialog.close();
        }
        responseDialogWidget = null;
        setResponseDialogStatus('');
    };

    const openResponseDialog = (widget) => {
        if (!responseDialog || !widget) {
            return;
        }
        responseDialogWidget = widget;
        const action = widget.querySelector('.item-response-action')?.value || 'open';
        const comment = widget.querySelector('.item-response-comment')?.value || '';
        const attribution = widget.querySelector('.item-response-attribution');

        if (responseDialogTitle) {
            responseDialogTitle.textContent = widget.dataset.itemTitle || 'Edit response';
        }
        if (responseDialogSub) {
            responseDialogSub.textContent = widget.dataset.itemSub || '';
            responseDialogSub.hidden = !widget.dataset.itemSub;
        }
        populateResponseDialogDetails(widget);
        if (responseDialogAction) {
            responseDialogAction.value = action;
        }
        if (responseDialogComment) {
            responseDialogComment.value = comment;
        }
        if (responseDialogAttribution) {
            const text = attribution && !attribution.hidden ? attribution.textContent.trim() : '';
            responseDialogAttribution.textContent = text;
            responseDialogAttribution.hidden = text === '';
        }
        const historyController = historyControllers.get('response-dialog-history');
        if (historyController) {
            historyController.setAssessmentId(assessmentId);
            historyController.setEntityKey(widget.dataset.itemKey || '');
            historyController.clearSearch();
            historyController.reload();
        }
        setResponseDialogStatus('');
        if (typeof responseDialog.showModal === 'function') {
            responseDialog.showModal();
        } else {
            responseDialog.setAttribute('open', 'open');
        }
        responseDialogAction?.focus();
    };

    document.querySelectorAll('.item-response-edit').forEach((button) => {
        button.addEventListener('click', () => {
            openResponseDialog(button.closest('.item-response'));
        });
    });

    document.getElementById('response-dialog-close')?.addEventListener('click', closeResponseDialog);
    document.getElementById('response-dialog-cancel')?.addEventListener('click', closeResponseDialog);

    responseDialog?.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeResponseDialog();
    });

    responseDialogForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const widget = responseDialogWidget;
        if (!widget) {
            closeResponseDialog();
            return;
        }
        const itemKey = widget.dataset.itemKey;
        const action = responseDialogAction?.value || 'open';
        const comment = responseDialogComment?.value || '';
        const actionSelect = widget.querySelector('.item-response-action');
        const commentField = widget.querySelector('.item-response-comment');
        const saveLabel = widget.querySelector('.item-response-save');
        if (actionSelect) {
            actionSelect.value = action;
        }
        if (commentField) {
            commentField.value = comment;
        }
        if (responseDialogSave) {
            responseDialogSave.disabled = true;
        }
        setResponseDialogStatus('Saving…');
        try {
            await persistResponse(itemKey, action, comment, saveLabel, widget, true);
            refreshResponseSummary(widget);
            historyControllers.get('response-dialog-history')?.reload();
            setResponseDialogStatus('Saved');
            window.setTimeout(() => {
                closeResponseDialog();
            }, 450);
        } catch (error) {
            setResponseDialogStatus(error?.message || 'Save failed', true);
        } finally {
            if (responseDialogSave) {
                responseDialogSave.disabled = false;
            }
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
    const actionSourceButtons = Array.from(document.querySelectorAll('.action-source-tab'));
    const actionSectionChips = Array.from(document.querySelectorAll('.action-section-chip'));
    let activeActionSource = 'all';
    let activeActionSection = '';

    const syncActionSectionChips = () => {
        const filters = document.getElementById('action-section-filters');
        if (!filters) {
            return;
        }
        let visibleChipCount = 0;
        actionSectionChips.forEach((chip) => {
            const chipSource = chip.dataset.actionSectionSource || 'all';
            const isAllChip = (chip.dataset.actionSection || '') === '';
            const show = isAllChip
                || activeActionSource === 'all'
                || chipSource === activeActionSource;
            chip.hidden = !show;
            if (show && !isAllChip) {
                visibleChipCount += 1;
            }
            const isActive = isAllChip
                ? activeActionSection === ''
                : activeActionSection !== '' && chip.dataset.actionSection === activeActionSection;
            chip.classList.toggle('is-active', isActive);
        });
        filters.hidden = visibleChipCount === 0;
        const label = filters.querySelector('.action-section-filters-label');
        if (label) {
            label.textContent = activeActionSource === 'due_diligence' ? 'Categories' : 'Sections';
        }
    };

    const applyActionSourceFilter = (source = 'all', section = activeActionSection) => {
        activeActionSource = source || 'all';
        activeActionSection = section || '';

        // Drop section filter if it no longer belongs to the selected source.
        if (activeActionSection !== '') {
            const stillValid = actionSectionChips.some((chip) => {
                const chipSection = chip.dataset.actionSection || '';
                const chipSource = chip.dataset.actionSectionSource || 'all';
                return chipSection === activeActionSection
                    && (activeActionSource === 'all' || chipSource === activeActionSource);
            });
            if (!stillValid) {
                activeActionSection = '';
            }
        }

        actionSourceButtons.forEach((button) => {
            const isActive = (button.dataset.actionSource || 'all') === activeActionSource;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        syncActionSectionChips();

        const counts = { risks: 0, gaps: 0, tbd: 0 };
        ['risks', 'gaps', 'tbd'].forEach((scope) => {
            const table = document.getElementById(`action-${scope}-table`);
            if (!table) {
                return;
            }
            const rows = Array.from(table.querySelectorAll('tbody tr[data-item-type]'));
            let visible = 0;
            rows.forEach((row) => {
                const itemType = row.dataset.itemType || 'architecture';
                const rowSection = row.dataset.section || '';
                const sourceMatch = activeActionSource === 'all' || itemType === activeActionSource;
                const sectionMatch = activeActionSection === '' || rowSection === activeActionSection;
                const show = sourceMatch && sectionMatch;
                row.classList.toggle('hidden', !show);
                if (show) {
                    visible += 1;
                }
            });
            counts[scope] = visible;

            const countEl = document.querySelector(`[data-workbench-count="${scope}"]`);
            if (countEl) {
                countEl.textContent = `${visible} item${visible === 1 ? '' : 's'}`;
            }
            const tabCount = document.querySelector(`[data-action-count="${scope}"]`);
            if (tabCount) {
                tabCount.textContent = String(visible);
            }
            const emptyEl = document.querySelector(`[data-action-source-empty="${scope}"]`);
            const helpEl = table.closest('.table-card')?.querySelector('.panel-help');
            const bulkBar = table.closest('.table-card')?.querySelector('.bulk-response-bar');
            const scroll = table.closest('.table-scroll');
            const hasAnyRows = rows.length > 0;
            if (emptyEl) {
                emptyEl.hidden = !(hasAnyRows && visible === 0);
            }
            if (helpEl) {
                helpEl.hidden = hasAnyRows && visible === 0;
            }
            if (bulkBar) {
                bulkBar.hidden = hasAnyRows && visible === 0;
            }
            if (scroll) {
                scroll.hidden = hasAnyRows && visible === 0;
            }
        });

        window.refreshBulkSelectionBars?.();
    };

    const writeActionFilterUrl = (tabName) => {
        const next = new URLSearchParams(window.location.search);
        next.set('tab', 'actions');
        next.set('action_tab', tabName || document.querySelector('.action-tab.is-active')?.dataset.actionTab || 'risks');
        if (activeActionSource && activeActionSource !== 'all') {
            next.set('action_source', activeActionSource);
        } else {
            next.delete('action_source');
        }
        if (activeActionSection) {
            next.set('action_section', activeActionSection);
        } else {
            next.delete('action_section');
        }
        window.history.replaceState({}, '', `${window.location.pathname}?${next.toString()}`);
    };

    const activateActionTab = (tabName, pushState = true) => {
        let target = tabName || 'risks';
        const hasTab = actionTabButtons.some((button) => button.dataset.actionTab === target);
        if (!hasTab) {
            target = 'risks';
        }
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
            writeActionFilterUrl(target);
        }
        window.requestAnimationFrame(() => window.syncStickyOffsets?.());
        window.refreshBulkSelectionBars?.();
    };
    window.activateActionTab = activateActionTab;
    window.applyActionSourceFilter = applyActionSourceFilter;

    actionTabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const scrollY = window.scrollY;
            activateActionTab(button.dataset.actionTab || 'risks');
            button.focus({ preventScroll: true });
            window.scrollTo({ top: scrollY, left: window.scrollX, behavior: 'instant' });
        });
    });

    actionSourceButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const scrollY = window.scrollY;
            applyActionSourceFilter(button.dataset.actionSource || 'all', activeActionSection);
            writeActionFilterUrl(document.querySelector('.action-tab.is-active')?.dataset.actionTab || 'risks');
            button.focus({ preventScroll: true });
            window.scrollTo({ top: scrollY, left: window.scrollX, behavior: 'instant' });
        });
    });

    actionSectionChips.forEach((chip) => {
        chip.addEventListener('click', () => {
            const scrollY = window.scrollY;
            applyActionSourceFilter(activeActionSource, chip.dataset.actionSection || '');
            writeActionFilterUrl(document.querySelector('.action-tab.is-active')?.dataset.actionTab || 'risks');
            chip.focus({ preventScroll: true });
            window.scrollTo({ top: scrollY, left: window.scrollX, behavior: 'instant' });
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
            historyControllers.get('evaluation-history')?.reload();
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
        applyActionSourceFilter(params.get('action_source') || 'all', params.get('action_section') || '');
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
        applyActionSourceFilter(params.get('action_source') || 'all', params.get('action_section') || '');
    } else if ((params.get('action_source') || params.get('action_section')) && actionSourceButtons.length) {
        activateTab('actions', false);
        activateActionTab(params.get('action_tab') || 'risks', false);
        applyActionSourceFilter(params.get('action_source') || 'all', params.get('action_section') || '');
    } else if (actionSourceButtons.length) {
        applyActionSourceFilter('all', '');
    }

    if (window.location.hash === '#final-evaluation' && params.get('tab') !== 'actions') {
        activateTab('actions', false);
        activateActionTab('signoff', false);
        document.getElementById('final-evaluation')?.scrollIntoView({ behavior: 'smooth' });
    }

    const registerAddDialog = document.getElementById('register-add-dialog');
    const registerAddForm = document.getElementById('register-add-dialog-form');
    const registerAddItemType = document.getElementById('register-add-item-type');
    const registerAddStatusEl = document.getElementById('register-add-dialog-status');
    const registerAddCheckLabel = document.getElementById('register-add-check-label');
    const registerAddDdFields = Array.from(document.querySelectorAll('.register-add-dd-only'));

    if (registerAddDialog && registerAddDialog.parentElement !== document.body) {
        document.body.appendChild(registerAddDialog);
    }

    const setRegisterAddStatus = (message, isError = false) => {
        if (!registerAddStatusEl) {
            return;
        }
        registerAddStatusEl.hidden = !message;
        registerAddStatusEl.textContent = message || '';
        registerAddStatusEl.classList.toggle('is-error', !!isError);
    };

    const closeRegisterAddDialog = () => {
        if (registerAddDialog && typeof registerAddDialog.close === 'function' && registerAddDialog.open) {
            registerAddDialog.close();
        }
        setRegisterAddStatus('');
    };

    const openRegisterAddDialog = (itemType) => {
        if (!registerAddDialog || Number(assessmentId) <= 0) {
            return;
        }
        const type = itemType === 'due_diligence' ? 'due_diligence' : 'architecture';
        if (registerAddItemType) {
            registerAddItemType.value = type;
        }
        const isDd = type === 'due_diligence';
        if (registerAddCheckLabel) {
            registerAddCheckLabel.textContent = isDd ? '✅ Assessment item' : '✅ Check';
        }
        registerAddDdFields.forEach((field) => {
            field.hidden = !isDd;
        });
        const eyebrow = document.getElementById('register-add-dialog-eyebrow');
        const title = document.getElementById('register-add-dialog-title');
        if (eyebrow) {
            eyebrow.textContent = isDd ? '➕ Add due diligence row' : '➕ Add architecture row';
        }
        if (title) {
            title.textContent = isDd ? 'Add due diligence row' : 'Add architecture row';
        }
        registerAddForm?.reset();
        if (registerAddItemType) {
            registerAddItemType.value = type;
        }
        const statusSelect = document.getElementById('register-add-status');
        if (statusSelect) {
            statusSelect.value = 'TBD';
        }
        setRegisterAddStatus('');
        if (typeof registerAddDialog.showModal === 'function') {
            registerAddDialog.showModal();
        } else {
            registerAddDialog.setAttribute('open', 'open');
        }
        document.getElementById('register-add-section')?.focus();
    };

    document.querySelectorAll('.register-add-row').forEach((button) => {
        button.addEventListener('click', () => {
            openRegisterAddDialog(button.dataset.itemType || 'architecture');
        });
    });

    document.getElementById('register-add-dialog-close')?.addEventListener('click', closeRegisterAddDialog);
    document.getElementById('register-add-dialog-cancel')?.addEventListener('click', closeRegisterAddDialog);
    registerAddDialog?.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeRegisterAddDialog();
    });

    registerAddForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (Number(assessmentId) <= 0) {
            setRegisterAddStatus('Save this assessment first.', true);
            return;
        }
        const saveBtn = document.getElementById('register-add-dialog-save');
        if (saveBtn) {
            saveBtn.disabled = true;
        }
        setRegisterAddStatus('Saving…');
        try {
            const body = new URLSearchParams({
                action: 'add_assessment_item',
                csrf_token: csrfToken,
                assessment_id: String(assessmentId),
                item_type: registerAddItemType?.value || 'architecture',
                section: document.getElementById('register-add-section')?.value || '',
                check: document.getElementById('register-add-check')?.value || '',
                status: document.getElementById('register-add-status')?.value || 'TBD',
                risk_level: document.getElementById('register-add-risk')?.value || '',
                notes: document.getElementById('register-add-notes')?.value || '',
                mitigation: document.getElementById('register-add-mitigation')?.value || '',
                owner: document.getElementById('register-add-owner')?.value || '',
                remediation_timeline: document.getElementById('register-add-timeline')?.value || '',
                review_question: document.getElementById('register-add-review-question')?.value || '',
                source_reference: document.getElementById('register-add-source-ref')?.value || '',
            });
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Unable to add row.');
            }
            setRegisterAddStatus('Saved. Reloading…');
            window.location.reload();
        } catch (error) {
            setRegisterAddStatus(error instanceof Error ? error.message : 'Unable to add row.', true);
            if (saveBtn) {
                saveBtn.disabled = false;
            }
        }
    });

    document.querySelectorAll('.register-row-delete').forEach((button) => {
        button.addEventListener('click', async () => {
            const itemId = button.dataset.itemId || '';
            const title = button.dataset.itemTitle || 'this row';
            if (!itemId || Number(assessmentId) <= 0) {
                return;
            }
            if (!window.confirm(`Delete “${title}”? This removes it from the current assessment only.`)) {
                return;
            }
            button.disabled = true;
            try {
                const body = new URLSearchParams({
                    action: 'delete_assessment_item',
                    csrf_token: csrfToken,
                    assessment_id: String(assessmentId),
                    item_id: String(itemId),
                });
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body,
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || 'Unable to delete row.');
                }
                window.location.reload();
            } catch (error) {
                button.disabled = false;
                window.alert(error instanceof Error ? error.message : 'Unable to delete row.');
            }
        });
    });

    // Adaptive Question Router filters (decision / module / search)
    const routerTable = document.getElementById('adaptive-router-table');
    if (routerTable) {
        const routerPanel = routerTable.closest('.dash-panel');
        const routerRows = Array.from(routerTable.querySelectorAll('tbody tr'));
        const routerSearch = document.querySelector('[data-router-search]');
        const routerCount = document.querySelector('[data-router-count]');
        const routerClear = document.querySelector('[data-router-clear]');
        const routerKpis = Array.from((routerPanel || document).querySelectorAll('.router-kpis .kpi-clickable'));
        const routerChips = Array.from((routerPanel || document).querySelectorAll('.router-chip'));
        const routerModuleBars = Array.from((routerPanel || document).querySelectorAll('.adaptive-module-bars .section-bar-row'));
        let routerDecision = '';
        let routerModule = '';

        const syncRouterControls = () => {
            routerKpis.forEach((kpi) => {
                const type = kpi.dataset.filterType || '';
                const value = kpi.dataset.filterValue || '';
                const active = (type === 'all' && !routerDecision && !routerModule)
                    || (type === 'router_decision' && value === routerDecision && !routerModule);
                kpi.classList.toggle('is-active', active);
                kpi.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            routerChips.forEach((chip) => {
                const type = chip.dataset.filterType || '';
                const value = chip.dataset.filterValue || '';
                const active = (type === 'all' && !routerDecision && !routerModule)
                    || (type === 'router_decision' && value === routerDecision && !routerModule);
                chip.classList.toggle('is-active', active);
                chip.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            routerModuleBars.forEach((bar) => {
                bar.classList.toggle('is-active', (bar.dataset.filterValue || '') === routerModule);
            });
            if (routerClear) {
                const hasFilter = Boolean(routerDecision || routerModule || (routerSearch?.value || '').trim());
                routerClear.hidden = !hasFilter;
            }
        };

        const applyRouterFilters = () => {
            const q = (routerSearch?.value || '').trim().toLowerCase();
            let visible = 0;
            routerRows.forEach((row) => {
                const decision = row.dataset.routerDecision || '';
                const module = row.dataset.routerModule || '';
                const search = row.dataset.search || '';
                const matchDecision = !routerDecision || decision === routerDecision;
                const matchModule = !routerModule || module === routerModule;
                const matchSearch = !q || search.includes(q);
                const show = matchDecision && matchModule && matchSearch;
                row.hidden = !show;
                if (show) {
                    visible += 1;
                }
            });
            if (routerCount) {
                routerCount.textContent = `${visible} scenario${visible === 1 ? '' : 's'}`;
            }
            syncRouterControls();
        };

        const setRouterFilter = (filterType, filterValue) => {
            if (filterType === 'all') {
                routerDecision = '';
                routerModule = '';
            } else if (filterType === 'router_decision') {
                routerDecision = routerDecision === filterValue ? '' : (filterValue || '');
                routerModule = '';
            } else if (filterType === 'router_module') {
                routerModule = routerModule === filterValue ? '' : (filterValue || '');
                routerDecision = '';
            }
            activateTab('router', true);
            applyRouterFilters();
            const first = routerRows.find((row) => !row.hidden);
            first?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        };

        routerSearch?.addEventListener('input', applyRouterFilters);
        routerClear?.addEventListener('click', () => {
            routerDecision = '';
            routerModule = '';
            if (routerSearch) {
                routerSearch.value = '';
            }
            applyRouterFilters();
        });

        (routerPanel || document).querySelectorAll(
            '.router-kpis .kpi-clickable, .router-chip, .adaptive-module-bars .section-bar-row, [data-filter-type="router_decision"], [data-filter-type="router_module"]'
        ).forEach((el) => {
            el.addEventListener('click', (event) => {
                // Avoid double-binding when legend/donut handlers also fire.
                if (el.closest('.router-kpis, .router-filter-chips, .adaptive-module-bars')) {
                    event.stopPropagation();
                }
                const type = el.dataset.filterType || '';
                const value = el.dataset.filterValue || '';
                if (!type) {
                    return;
                }
                setRouterFilter(type, value);
            });
        });

        applyRouterFilters();
    }

    const copyShareBtn = document.getElementById('btn-copy-share-link');
    const shareUrlInput = document.getElementById('share-link-url');
    const shareCopyStatus = document.getElementById('share-link-copy-status');
    const copyShareText = async (input) => {
        const value = String(input?.value || '');
        if (!value) return false;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(value);
            return true;
        }
        const holder = document.createElement('textarea');
        holder.value = value;
        holder.setAttribute('readonly', '');
        holder.style.position = 'fixed';
        holder.style.left = '-9999px';
        document.body.appendChild(holder);
        holder.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(holder);
        if (!ok) {
            throw new Error('copy failed');
        }
        return true;
    };
    const markShareCopied = (btn, statusEl, message) => {
        if (statusEl) {
            statusEl.hidden = false;
            statusEl.textContent = message;
        }
        if (!(btn instanceof HTMLElement)) return;
        const original = btn.getAttribute('data-copy-label') || btn.textContent || '📋 Copy';
        if (!btn.getAttribute('data-copy-label')) {
            btn.setAttribute('data-copy-label', original);
        }
        btn.classList.add('is-copied');
        btn.textContent = '✓ Copied';
        window.clearTimeout(Number(btn.getAttribute('data-copy-timer') || 0));
        const timer = window.setTimeout(() => {
            btn.classList.remove('is-copied');
            btn.textContent = btn.getAttribute('data-copy-label') || original;
        }, 1600);
        btn.setAttribute('data-copy-timer', String(timer));
    };
    if (copyShareBtn && shareUrlInput) {
        copyShareBtn.addEventListener('click', async () => {
            try {
                await copyShareText(shareUrlInput);
                markShareCopied(copyShareBtn, shareCopyStatus, 'Link copied to clipboard.');
            } catch (error) {
                markShareCopied(copyShareBtn, shareCopyStatus, 'Unable to copy automatically. Hover the preview for the full link.');
            }
        });
    }
    document.querySelectorAll('#share-link-panel .share-link-copy-btn').forEach((btn) => {
        if (btn.id === 'btn-copy-share-link') return;
        btn.addEventListener('click', async () => {
            const input = document.getElementById(btn.getAttribute('data-copy-input') || '');
            const statusEl = document.getElementById(btn.getAttribute('data-copy-status') || '');
            if (!input) return;
            try {
                await copyShareText(input);
                markShareCopied(btn, statusEl, 'Link copied to clipboard.');
            } catch {
                markShareCopied(btn, statusEl, 'Unable to copy automatically. Hover the preview for the full link.');
            }
        });
    });
});
