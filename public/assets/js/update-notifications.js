(function () {
    const root = document.getElementById('update-notify-root');
    if (!root || window.__raUpdateNotifyInit) {
        return;
    }
    window.__raUpdateNotifyInit = true;

    const STORAGE_FP = 'ra-updater-notified-fp';
    const STORAGE_TOAST = 'ra-updater-toast-enabled';
    const STORAGE_DESKTOP = 'ra-updater-desktop-enabled';
    const POLL_MS = 5 * 60 * 1000;
    const TOAST_MS = 9000;
    const statusUrl = root.getAttribute('data-status-url') || '';
    const updatesUrl = root.getAttribute('data-updates-url') || '';
    const brandTitle = root.getAttribute('data-brand-title') || 'Risk Register';
    const iconUrl = root.getAttribute('data-icon-url') || '';
    let csrfToken = root.getAttribute('data-csrf-token') || '';
    const btn = document.getElementById('update-notify-btn');
    const panel = document.getElementById('update-notify-panel');
    const badge = document.getElementById('update-notify-badge');
    const statusEl = document.getElementById('update-notify-status');
    const listEl = document.getElementById('update-notify-list');
    const refreshBtn = document.getElementById('update-notify-refresh');
    const markBtn = document.getElementById('update-notify-mark');
    const toastPref = document.getElementById('update-notify-toast-pref');
    const desktopPref = document.getElementById('update-notify-desktop-pref');
    const desktopWrap = document.getElementById('update-notify-desktop-wrap');
    const desktopLabel = document.getElementById('update-notify-desktop-label');
    const closeBtns = root.querySelectorAll('[data-update-notify-close]');

    let lastFingerprint = '';
    let toastTimer = 0;

    const escapeHtml = (value) => String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const notificationSupported = () => (
        typeof window.Notification === 'function'
        && (window.isSecureContext || location.hostname === 'localhost' || location.hostname === '127.0.0.1')
    );

    const storedFlag = (key, fallback) => {
        try {
            const value = localStorage.getItem(key);
            if (value === '0') {
                return false;
            }
            if (value === '1') {
                return true;
            }
        } catch {
            // Ignore quota / private-mode failures.
        }
        return fallback;
    };

    const storeFlag = (key, enabled) => {
        try {
            localStorage.setItem(key, enabled ? '1' : '0');
        } catch {
            // Ignore quota / private-mode failures.
        }
    };

    const toastEnabled = () => storedFlag(STORAGE_TOAST, true);
    const desktopEnabled = () => storedFlag(
        STORAGE_DESKTOP,
        notificationSupported() && Notification.permission === 'granted'
    );

    const storedFingerprint = () => {
        try {
            return localStorage.getItem(STORAGE_FP) || '';
        } catch {
            return '';
        }
    };

    const rememberFingerprint = (fingerprint) => {
        try {
            localStorage.setItem(STORAGE_FP, fingerprint);
        } catch {
            // Ignore quota / private-mode failures.
        }
    };

    const setOpen = (open) => {
        if (!btn || !panel) {
            return;
        }
        panel.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        root.classList.toggle('is-open', open);
    };

    const syncPrefControls = () => {
        if (toastPref) {
            toastPref.checked = toastEnabled();
        }
        if (!desktopPref) {
            return;
        }
        if (!notificationSupported()) {
            desktopPref.checked = false;
            desktopPref.disabled = true;
            desktopWrap?.classList.add('is-disabled');
            if (desktopLabel) {
                desktopLabel.textContent = 'Browser notifications unavailable';
            }
            return;
        }
        if (Notification.permission === 'denied') {
            desktopPref.checked = false;
            desktopPref.disabled = true;
            desktopWrap?.classList.add('is-disabled');
            if (desktopLabel) {
                desktopLabel.textContent = 'Browser notifications blocked';
            }
            storeFlag(STORAGE_DESKTOP, false);
            return;
        }
        desktopPref.disabled = false;
        desktopWrap?.classList.remove('is-disabled');
        desktopPref.checked = desktopEnabled() && Notification.permission === 'granted';
        if (desktopLabel) {
            desktopLabel.textContent = 'Browser notifications';
        }
    };

    const summaryText = (state) => {
        const count = Number(state.aheadBy) || 0;
        if (!state.ok) {
            return state.error || 'Could not check GitHub for updates.';
        }
        if (count <= 0) {
            const installed = state.installedLabel ? ` (${state.installedLabel})` : '';
            return `This install is up to date${installed}.`;
        }
        const noun = count === 1 ? 'update' : 'updates';
        if (state.mode === 'releases') {
            return `${count} newer GitHub Release${count === 1 ? '' : 's'} available.`;
        }
        if (state.mode === 'mixed') {
            return `${count} ${noun} available (Releases and commits).`;
        }
        return `${count} ${noun} available on GitHub.`;
    };

    const syncNavBadge = (count) => {
        document.querySelectorAll('[data-update-nav="updates"]').forEach((link) => {
            let mark = link.querySelector('.update-nav-badge');
            if (count <= 0) {
                if (mark) {
                    mark.remove();
                }
                return;
            }
            if (!mark) {
                mark = document.createElement('span');
                mark.className = 'update-nav-badge';
                link.appendChild(mark);
            }
            mark.textContent = count > 9 ? '9+' : String(count);
        });
    };

    const renderPanel = (state) => {
        const count = Number(state.aheadBy) || 0;
        const available = Boolean(state.available);
        root.classList.toggle('has-update', available);
        if (btn) {
            btn.classList.toggle('has-new', available);
            const label = available
                ? `${count} app ${count === 1 ? 'update' : 'updates'} available`
                : 'App update notifications';
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
        }
        if (badge) {
            badge.hidden = !available;
            badge.textContent = count > 9 ? '9+' : String(count);
        }
        if (statusEl) {
            statusEl.textContent = summaryText(state);
            statusEl.classList.toggle('is-error', !state.ok);
            statusEl.classList.toggle('is-ready', state.ok && available);
        }
        if (listEl) {
            const items = Array.isArray(state.items) ? state.items : [];
            listEl.innerHTML = '';
            if (!available || items.length === 0) {
                listEl.hidden = true;
            } else {
                items.forEach((item) => {
                    const li = document.createElement('li');
                    const kind = item.kind === 'release' ? 'Release' : 'Commit';
                    li.innerHTML = `<span class="update-bell-item-kind">${escapeHtml(kind)}</span>`
                        + `<span class="update-bell-item-short">${escapeHtml(item.short || '')}</span>`
                        + `<span class="update-bell-item-msg">${escapeHtml(item.message || '')}</span>`;
                    listEl.appendChild(li);
                });
                listEl.hidden = false;
            }
        }
        if (markBtn) {
            markBtn.hidden = !available;
            markBtn.disabled = false;
        }
        if (typeof state.csrf_token === 'string' && state.csrf_token !== '') {
            csrfToken = state.csrf_token;
            root.setAttribute('data-csrf-token', csrfToken);
        }
        syncNavBadge(available ? count : 0);
        syncPrefControls();
    };

    const toastHost = () => {
        let host = document.getElementById('update-toast-host');
        if (host) {
            return host;
        }
        host = document.createElement('div');
        host.id = 'update-toast-host';
        host.className = 'update-toast-host';
        host.setAttribute('aria-live', 'polite');
        document.body.appendChild(host);
        return host;
    };

    const hideToast = () => {
        const existing = document.getElementById('update-toast');
        if (existing) {
            existing.remove();
        }
        if (toastTimer) {
            window.clearTimeout(toastTimer);
            toastTimer = 0;
        }
    };

    const showToast = (state) => {
        if (!toastEnabled()) {
            return;
        }
        hideToast();
        const toast = document.createElement('div');
        toast.id = 'update-toast';
        toast.className = 'update-toast';
        toast.setAttribute('role', 'status');
        const detail = state.latestMessage
            ? escapeHtml(state.latestLabel ? `${state.latestLabel} · ${state.latestMessage}` : state.latestMessage)
            : escapeHtml(summaryText(state));
        toast.innerHTML = `<strong>App update available</strong>`
            + `<p>${detail}</p>`
            + `<div class="update-toast-actions">`
            + `<a class="button button-primary" href="${escapeHtml(updatesUrl)}">View updates</a>`
            + `<button type="button" class="button ghost" data-update-toast-dismiss>Dismiss</button>`
            + `</div>`;
        toast.querySelector('[data-update-toast-dismiss]')?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            hideToast();
        });
        toast.addEventListener('click', (event) => {
            if (event.target.closest('[data-update-toast-dismiss]')) {
                return;
            }
            if (event.target.closest('a')) {
                return;
            }
            window.location.href = updatesUrl;
        });
        toastHost().appendChild(toast);
        toastTimer = window.setTimeout(hideToast, TOAST_MS);
    };

    const iconAbsolute = () => {
        if (!iconUrl) {
            return '';
        }
        try {
            return new URL(iconUrl, window.location.href).href;
        } catch {
            return iconUrl;
        }
    };

    const showBrowserNotification = (state) => {
        if (!desktopEnabled() || !notificationSupported() || Notification.permission !== 'granted') {
            return;
        }
        const count = Number(state.aheadBy) || 0;
        const body = state.latestMessage
            ? (state.latestLabel ? `${state.latestLabel} · ${state.latestMessage}` : state.latestMessage)
            : summaryText(state);
        const options = {
            body,
            tag: 'ra-app-update',
            renotify: true,
            silent: false,
        };
        const icon = iconAbsolute();
        if (icon) {
            options.icon = icon;
        }
        let note;
        try {
            note = new Notification(`${brandTitle}: ${count} update${count === 1 ? '' : 's'}`, options);
        } catch {
            return;
        }
        note.onclick = () => {
            window.focus();
            window.location.href = updatesUrl;
            note.close();
        };
    };

    const announceIfNew = (state) => {
        if (!state.ok || !state.available || !state.fingerprint) {
            return;
        }
        if (state.fingerprint === lastFingerprint) {
            return;
        }
        lastFingerprint = state.fingerprint;
        if (storedFingerprint() === state.fingerprint) {
            return;
        }
        rememberFingerprint(state.fingerprint);
        showToast(state);
        showBrowserNotification(state);
    };

    const applyState = (state, announce) => {
        renderPanel(state);
        if (announce) {
            announceIfNew(state);
        } else if (state.fingerprint) {
            lastFingerprint = state.fingerprint;
        }
    };

    const fetchState = async (refresh) => {
        if (!statusUrl) {
            return;
        }
        const url = refresh ? `${statusUrl}${statusUrl.includes('?') ? '&' : '?'}refresh=1` : statusUrl;
        if (refreshBtn) {
            refreshBtn.disabled = true;
        }
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const state = await response.json();
            if (!response.ok || !state || typeof state !== 'object') {
                applyState({
                    ok: false,
                    error: (state && state.error) ? state.error : 'Could not check for updates.',
                    available: false,
                    aheadBy: 0,
                    items: [],
                }, false);
                return;
            }
            applyState(state, true);
        } catch (error) {
            applyState({
                ok: false,
                error: error instanceof Error ? error.message : 'Could not check for updates.',
                available: false,
                aheadBy: 0,
                items: [],
            }, false);
        } finally {
            if (refreshBtn) {
                refreshBtn.disabled = false;
            }
        }
    };

    const markComplete = async () => {
        if (!statusUrl || !csrfToken) {
            if (statusEl) {
                statusEl.textContent = 'Could not mark updates complete. Refresh the page and try again.';
                statusEl.classList.add('is-error');
            }
            return;
        }
        const confirmed = window.confirm(
            'Mark these updates as already installed on this system? No files will be downloaded. Use this on a developer machine where the code is already current.'
        );
        if (!confirmed) {
            return;
        }
        if (markBtn) {
            markBtn.disabled = true;
        }
        if (statusEl) {
            statusEl.textContent = 'Marking updates as already installed…';
            statusEl.classList.remove('is-error', 'is-ready');
        }
        try {
            const response = await fetch(statusUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action: 'mark_installed',
                    csrf_token: csrfToken,
                    target_ref: '',
                }),
            });
            const state = await response.json();
            if (!response.ok || !state || typeof state !== 'object' || !state.ok) {
                applyState({
                    ok: false,
                    error: (state && state.error) ? state.error : 'Could not mark updates as installed.',
                    available: false,
                    aheadBy: 0,
                    items: [],
                }, false);
                return;
            }
            hideToast();
            applyState(state, false);
            if (statusEl && state.message) {
                statusEl.textContent = state.message;
                statusEl.classList.remove('is-error');
            }
        } catch (error) {
            applyState({
                ok: false,
                error: error instanceof Error ? error.message : 'Could not mark updates as installed.',
                available: false,
                aheadBy: 0,
                items: [],
            }, false);
        } finally {
            if (markBtn) {
                markBtn.disabled = false;
            }
        }
    };

    if (btn && panel) {
        btn.addEventListener('click', (event) => {
            event.stopPropagation();
            setOpen(panel.hidden);
        });
    }
    closeBtns.forEach((closeBtn) => {
        closeBtn.addEventListener('click', () => setOpen(false));
    });
    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) {
            setOpen(false);
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
            hideToast();
        }
    });
    refreshBtn?.addEventListener('click', () => {
        if (statusEl) {
            statusEl.textContent = 'Checking GitHub for updates…';
            statusEl.classList.remove('is-error', 'is-ready');
        }
        fetchState(true);
    });
    markBtn?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        markComplete();
    });
    toastPref?.addEventListener('change', () => {
        storeFlag(STORAGE_TOAST, Boolean(toastPref.checked));
        if (!toastPref.checked) {
            hideToast();
        }
    });
    desktopPref?.addEventListener('change', async () => {
        if (!desktopPref.checked) {
            storeFlag(STORAGE_DESKTOP, false);
            syncPrefControls();
            return;
        }
        if (!notificationSupported()) {
            storeFlag(STORAGE_DESKTOP, false);
            syncPrefControls();
            return;
        }
        let permission = Notification.permission;
        if (permission === 'default') {
            try {
                permission = await Notification.requestPermission();
            } catch {
                permission = Notification.permission;
            }
        }
        const granted = permission === 'granted';
        storeFlag(STORAGE_DESKTOP, granted);
        syncPrefControls();
        if (granted && root.classList.contains('has-update')) {
            showBrowserNotification({
                ok: true,
                available: true,
                aheadBy: Number(badge?.textContent) || 1,
                latestMessage: statusEl?.textContent || 'An app update is available.',
                latestLabel: '',
            });
        }
    });

    syncPrefControls();
    fetchState(false);
    window.setInterval(() => fetchState(false), POLL_MS);
})();
