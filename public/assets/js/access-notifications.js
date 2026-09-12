(function () {
    const root = document.getElementById('shared-access-root');
    if (!root || window.__raAccessNotifyInit) {
        return;
    }
    window.__raAccessNotifyInit = true;

    const notifyUrl = root.getAttribute('data-notify-url') || 'access-notifications.php';
    let csrfToken = root.getAttribute('data-csrf') || '';
    const badge = document.getElementById('shared-access-badge');
    const btn = document.getElementById('shared-access-btn');
    const panel = document.getElementById('shared-access-panel');
    const noticesList = document.getElementById('shared-access-notices');
    const noticesSection = document.getElementById('shared-access-notices-section');
    const ackBtn = document.getElementById('shared-access-ack');
    let unreadCount = Number(root.getAttribute('data-unread-count') || 0);
    let toastTimer = 0;
    let markedPanelRead = false;
    let ackInFlight = false;

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const setAckVisible = (visible) => {
        if (!ackBtn) {
            return;
        }
        ackBtn.hidden = !visible;
    };

    const setBadge = (count) => {
        const display = Math.max(0, Number(count) || 0);
        if (!badge) {
            return;
        }
        if (display > 0) {
            badge.hidden = false;
            badge.textContent = display > 99 ? '99+' : String(display);
            btn?.classList.add('has-shared');
        } else {
            badge.hidden = true;
            badge.textContent = '0';
            btn?.classList.remove('has-shared');
        }
        setAckVisible(display > 0);
    };

    const postJson = async (payload) => {
        const body = Object.assign({ csrf_token: csrfToken }, payload);
        const response = await fetch(notifyUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || !data.ok) {
            throw new Error((data && data.error) || 'Request failed');
        }
        if (data.csrf_token) {
            csrfToken = data.csrf_token;
            root.setAttribute('data-csrf', csrfToken);
        }
        return data;
    };

    const toastHost = () => {
        let host = document.getElementById('app-toast-host');
        if (host) {
            return host;
        }
        host = document.getElementById('update-toast-host');
        if (host) {
            host.classList.add('app-toast-host');
            return host;
        }
        host = document.createElement('div');
        host.id = 'app-toast-host';
        host.className = 'app-toast-host update-toast-host';
        host.setAttribute('aria-live', 'polite');
        document.body.appendChild(host);
        return host;
    };

    const hideAccessToast = () => {
        const existing = document.getElementById('access-toast');
        if (existing) {
            existing.remove();
        }
        if (toastTimer) {
            window.clearTimeout(toastTimer);
            toastTimer = 0;
        }
    };

    const showAccessToast = (notice) => {
        hideAccessToast();
        const toast = document.createElement('div');
        toast.id = 'access-toast';
        toast.className = 'app-toast update-toast';
        toast.setAttribute('role', 'status');
        const title = notice.title || 'Access update';
        const body = notice.body || '';
        let href = notice.link_url || 'index.php#find-projects';
        if (href && !/^(https?:)?\/\//i.test(href) && href.charAt(0) !== '/') {
            const base = notifyUrl.replace(/access-notifications\.php.*$/i, '');
            href = base + href.replace(/^\//, '');
        }
        toast.innerHTML = `<strong>${escapeHtml(title)}</strong>`
            + `<p>${escapeHtml(body)}</p>`
            + `<div class="update-toast-actions app-toast-actions">`
            + `<a class="button button-primary" href="${escapeHtml(href)}">Open</a>`
            + `<button type="button" class="button ghost" data-access-toast-dismiss>Dismiss</button>`
            + `</div>`;
        toast.querySelector('[data-access-toast-dismiss]')?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            hideAccessToast();
        });
        toast.addEventListener('click', (event) => {
            if (event.target.closest('[data-access-toast-dismiss]') || event.target.closest('a')) {
                return;
            }
            window.location.href = href;
        });
        toastHost().appendChild(toast);
        toastTimer = window.setTimeout(hideAccessToast, 12000);
    };

    const clearNewChips = () => {
        noticesList?.querySelectorAll('.shared-access-new-chip').forEach((chip) => chip.remove());
        noticesList?.querySelectorAll('.shared-access-notice.is-unread').forEach((item) => {
            item.classList.remove('is-unread');
        });
    };

    const markAllRead = async () => {
        if (unreadCount <= 0 || ackInFlight) {
            return;
        }
        ackInFlight = true;
        try {
            const data = await postJson({ action: 'mark_all_read' });
            unreadCount = Number(data.unread_count || 0);
            root.setAttribute('data-unread-count', String(unreadCount));
            clearNewChips();
            setBadge(unreadCount);
            markedPanelRead = true;
        } catch {
            // Soft-fail: caller may retry.
        } finally {
            ackInFlight = false;
        }
    };

    const markPanelRead = async () => {
        if (markedPanelRead || unreadCount <= 0) {
            return;
        }
        await markAllRead();
    };

    const markToastRead = async (ids) => {
        if (!ids || ids.length === 0) {
            return;
        }
        try {
            const data = await postJson({ action: 'mark_read', ids: ids });
            unreadCount = Number(data.unread_count || 0);
            root.setAttribute('data-unread-count', String(unreadCount));
            setBadge(unreadCount);
            ids.forEach((id) => {
                const item = noticesList?.querySelector('[data-notice-id="' + id + '"]');
                if (!item) {
                    return;
                }
                item.classList.remove('is-unread');
                item.querySelector('.shared-access-new-chip')?.remove();
            });
        } catch {
            // Soft-fail: toast already shown.
        }
    };

    const bootstrapToasts = async () => {
        try {
            const response = await fetch(notifyUrl + (notifyUrl.includes('?') ? '&' : '?') + 'action=list', {
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
            unreadCount = Number(data.unread_count || 0);
            root.setAttribute('data-unread-count', String(unreadCount));
            setBadge(unreadCount);

            const toastItems = Array.isArray(data.toast) ? data.toast : [];
            if (toastItems.length === 0) {
                return;
            }
            const first = toastItems[0];
            showAccessToast(first);
            const ids = toastItems.map((item) => Number(item.id || 0)).filter((id) => id > 0);
            await markToastRead(ids);
        } catch {
            // Ignore bootstrap failures.
        }
    };

    // Mark notices read when the people panel opens.
    const observePanel = () => {
        if (!panel || !btn) {
            return;
        }
        const originalClick = () => {
            if (!panel.hidden) {
                markPanelRead();
            }
        };
        btn.addEventListener('click', () => {
            window.setTimeout(originalClick, 0);
        });
    };

    ackBtn?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        markAllRead();
    });

    observePanel();
    setBadge(unreadCount);
    bootstrapToasts();
})();
