(function () {
    const root = document.getElementById('exception-bell-root');
    if (!root || window.__raExceptionBellInit) {
        return;
    }
    window.__raExceptionBellInit = true;

    const notifyUrl = root.getAttribute('data-notify-url') || 'exception-notifications.php';
    const extendUrl = root.getAttribute('data-extend-url') || 'index.php';
    let csrfToken = root.getAttribute('data-csrf') || '';
    const minExtend = root.getAttribute('data-min-extend') || '';
    const badge = document.getElementById('exception-bell-badge');
    const btn = document.getElementById('exception-bell-btn');
    const panel = document.getElementById('exception-bell-panel');
    const list = document.getElementById('exception-bell-list');
    const empty = document.getElementById('exception-bell-empty');
    const statusEl = document.getElementById('exception-bell-status');
    let dueCount = Number(root.getAttribute('data-due-count') || 0);

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const todayYmd = () => {
        const d = new Date();
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    };

    const addMonthsYmd = (baseYmd, months) => {
        const base = /^\d{4}-\d{2}-\d{2}$/.test(String(baseYmd || '')) ? String(baseYmd) : todayYmd();
        const parts = base.split('-').map(Number);
        const d = new Date(parts[0], parts[1] - 1, parts[2], 12, 0, 0);
        const day = d.getDate();
        d.setMonth(d.getMonth() + Number(months || 0));
        // Keep end-of-month stable when source day overflows (e.g. Jan 31 + 1 mo).
        if (d.getDate() < day) {
            d.setDate(0);
        }
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${dd}`;
    };

    const applyExtendPreset = (container, months) => {
        if (!container) {
            return;
        }
        const dateInput = container.querySelector('input[type="date"]');
        if (!(dateInput instanceof HTMLInputElement)) {
            return;
        }
        const today = todayYmd();
        let next = addMonthsYmd(today, months);
        if (next <= today) {
            next = addMonthsYmd(today, Math.max(1, Number(months) || 1));
        }
        dateInput.min = minExtend || today;
        dateInput.value = next;
        container.querySelectorAll('.exception-extend-preset').forEach((btnEl) => {
            btnEl.classList.toggle('is-active', Number(btnEl.getAttribute('data-months') || 0) === Number(months));
        });
        dateInput.focus();
    };

    const extendPresetsHtml = () => `
        <div class="exception-extend-presets" role="group" aria-label="Extend by">
            <button type="button" class="exception-extend-preset" data-months="1">1 mo</button>
            <button type="button" class="exception-extend-preset" data-months="3">3 mo</button>
            <button type="button" class="exception-extend-preset" data-months="6">6 mo</button>
            <button type="button" class="exception-extend-preset" data-months="9">9 mo</button>
            <button type="button" class="exception-extend-preset" data-months="12">1 yr</button>
            <button type="button" class="exception-extend-preset" data-months="24">2 yr</button>
        </div>
    `;

    const setBadge = (count) => {
        dueCount = Math.max(0, Number(count) || 0);
        root.setAttribute('data-due-count', String(dueCount));
        if (!badge) {
            return;
        }
        if (dueCount > 0) {
            badge.hidden = false;
            badge.textContent = dueCount > 99 ? '99+' : String(dueCount);
            btn?.classList.add('has-due');
        } else {
            badge.hidden = true;
            badge.textContent = '0';
            btn?.classList.remove('has-due');
        }
        if (statusEl) {
            statusEl.textContent = dueCount > 0
                ? `${dueCount} due or overdue`
                : 'No exceptions are due right now.';
        }
    };

    const closePanel = () => {
        if (!panel) {
            return;
        }
        panel.hidden = true;
        btn?.setAttribute('aria-expanded', 'false');
    };

    const openPanel = () => {
        if (!panel) {
            return;
        }
        panel.hidden = false;
        btn?.setAttribute('aria-expanded', 'true');
    };

    const togglePanel = () => {
        if (!panel) {
            return;
        }
        if (panel.hidden) {
            openPanel();
            refresh();
        } else {
            closePanel();
        }
    };

    const projectLink = (assessmentId) => {
        const prefix = notifyUrl.replace(/exception-notifications\.php.*$/, '');
        return `${prefix}index.php?view=1&id=${assessmentId}&tab=actions&action_tab=exceptions#exception-tracker`;
    };

    const renderList = (items) => {
        if (!list) {
            return;
        }
        list.innerHTML = '';
        if (!Array.isArray(items) || items.length === 0) {
            list.hidden = true;
            if (empty) {
                empty.hidden = false;
            }
            return;
        }
        list.hidden = false;
        if (empty) {
            empty.hidden = true;
        }
        items.forEach((item) => {
            const aid = Number(item.assessment_id || 0);
            const fid = String(item.finding_id || '');
            const canEdit = !!item.can_edit;
            const li = document.createElement('li');
            li.className = 'exception-bell-item is-due';
            li.dataset.assessmentId = String(aid);
            li.dataset.findingId = fid;
            li.dataset.canEdit = canEdit ? '1' : '0';
            li.innerHTML = `
                <div class="exception-bell-item-head">
                    <span class="exception-bell-project">${escapeHtml(item.project_name || 'Untitled project')}</span>
                    <span class="exception-bell-date">${escapeHtml(item.expires_at || '')}</span>
                </div>
                <p class="exception-bell-finding">${escapeHtml(item.finding_text || fid)}</p>
                <div class="exception-bell-actions">
                    <a class="button button-secondary" href="${escapeHtml(projectLink(aid))}">Open</a>
                    ${canEdit ? '<button type="button" class="button ghost exception-bell-extend-toggle">Extend</button>' : ''}
                </div>
                ${canEdit ? `
                    <div class="exception-bell-extend-inline" hidden>
                        ${extendPresetsHtml()}
                        <div class="exception-bell-extend-date-row">
                            <input type="date" min="${escapeHtml(minExtend)}" aria-label="New expiry date">
                            <button type="button" class="button button-primary exception-bell-extend-save">Save</button>
                        </div>
                    </div>
                ` : ''}
            `;
            list.appendChild(li);
        });
    };

    const refresh = async () => {
        try {
            const response = await fetch(`${notifyUrl}?action=list`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || !data.ok) {
                return;
            }
            if (data.csrf_token) {
                csrfToken = data.csrf_token;
                root.setAttribute('data-csrf', csrfToken);
            }
            setBadge(data.due_count || 0);
            renderList(data.due || []);
        } catch (error) {
            // Keep server-rendered list on failure.
        }
    };

    window.refreshExceptionBell = refresh;

    const extendException = async (assessmentId, findingId, expiresAt) => {
        const body = new URLSearchParams({
            action: 'extend_exception',
            csrf_token: csrfToken,
            assessment_id: String(assessmentId),
            finding_id: findingId,
            expires_at: expiresAt,
        });
        const response = await fetch(extendUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body,
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || !data.ok) {
            throw new Error((data && data.error) || 'Extend failed');
        }
        return data;
    };

    btn?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        togglePanel();
    });

    root.querySelectorAll('[data-exception-bell-close]').forEach((closeBtn) => {
        closeBtn.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            closePanel();
            btn?.focus();
        });
    });

    document.addEventListener('click', (event) => {
        if (!panel || panel.hidden) {
            return;
        }
        const target = event.target;
        if (!(target instanceof Node) || root.contains(target)) {
            return;
        }
        closePanel();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && panel && !panel.hidden) {
            closePanel();
            btn?.focus();
        }
    });

    list?.addEventListener('click', async (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const item = target.closest('.exception-bell-item');
        if (!item) {
            return;
        }
        if (target.closest('.exception-bell-extend-toggle')) {
            const inline = item.querySelector('.exception-bell-extend-inline');
            if (inline) {
                inline.hidden = !inline.hidden;
                if (!inline.hidden) {
                    inline.querySelector('input[type="date"]')?.focus();
                }
            }
            return;
        }
        const presetBtn = target.closest('.exception-extend-preset');
        if (presetBtn) {
            const months = Number(presetBtn.getAttribute('data-months') || 0);
            const inline = item.querySelector('.exception-bell-extend-inline');
            if (months > 0 && inline) {
                inline.hidden = false;
                applyExtendPreset(inline, months);
            }
            return;
        }
        const saveBtn = target.closest('.exception-bell-extend-save');
        if (!saveBtn) {
            return;
        }
        const assessmentId = Number(item.dataset.assessmentId || 0);
        const findingId = item.dataset.findingId || '';
        const dateInput = item.querySelector('.exception-bell-extend-inline input[type="date"]');
        const expiresAt = dateInput?.value || '';
        if (!assessmentId || !findingId || !expiresAt) {
            window.alert('Choose a new expiry date after today.');
            dateInput?.focus();
            return;
        }
        if (expiresAt <= todayYmd()) {
            window.alert('Choose a new expiry date after today.');
            dateInput?.focus();
            return;
        }
        saveBtn.disabled = true;
        try {
            await extendException(assessmentId, findingId, expiresAt);
            await refresh();
        } catch (error) {
            window.alert(error.message || 'Extend failed');
        } finally {
            saveBtn.disabled = false;
        }
    });

    // Soft refresh so counts stay current after other tabs save.
    window.setTimeout(refresh, 1500);
    window.setInterval(refresh, 5 * 60 * 1000);
})();
