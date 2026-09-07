(function () {
    const root = document.getElementById('shared-access-root');
    if (!root || window.__raSharedAccessInit) {
        return;
    }
    window.__raSharedAccessInit = true;

    const btn = document.getElementById('shared-access-btn');
    const panel = document.getElementById('shared-access-panel');
    const searchInput = document.getElementById('shared-access-search');
    const listEl = document.getElementById('shared-access-list');
    const emptyEl = document.getElementById('shared-access-empty');
    const statusEl = document.getElementById('shared-access-status');
    const closeBtns = root.querySelectorAll('[data-shared-access-close]');
    const items = Array.from(root.querySelectorAll('.shared-access-item'));

    if (!btn || !panel) {
        return;
    }

    const setOpen = (open) => {
        panel.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && searchInput && !searchInput.disabled) {
            window.setTimeout(() => searchInput.focus(), 0);
        }
    };

    const applySearch = () => {
        if (!listEl) {
            return;
        }
        const query = (searchInput?.value || '').trim().toLowerCase();
        let visible = 0;
        items.forEach((item) => {
            const haystack = item.getAttribute('data-search') || '';
            const project = item.getAttribute('data-project') || '';
            const owner = item.getAttribute('data-owner') || '';
            const match = query === ''
                || haystack.includes(query)
                || project.includes(query)
                || owner.includes(query);
            item.hidden = !match;
            if (match) {
                visible += 1;
            }
        });

        if (emptyEl) {
            emptyEl.hidden = !(query !== '' && visible === 0 && items.length > 0);
        }
        if (listEl) {
            listEl.hidden = items.length === 0 || (query !== '' && visible === 0);
        }
        if (statusEl && items.length > 0) {
            if (query === '') {
                statusEl.textContent = items.length === 1
                    ? '1 project others shared with you for editing.'
                    : items.length + ' projects others shared with you for editing.';
            } else {
                statusEl.textContent = visible === 0
                    ? 'No matches for “' + query + '”.'
                    : (visible === 1 ? '1 match' : visible + ' matches') + ' for “' + query + '”.';
            }
        }
    };

    btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        setOpen(panel.hidden);
    });

    closeBtns.forEach((closeBtn) => {
        closeBtn.addEventListener('click', (event) => {
            event.preventDefault();
            setOpen(false);
            btn.focus();
        });
    });

    document.addEventListener('click', (event) => {
        if (panel.hidden) {
            return;
        }
        const target = event.target;
        if (!(target instanceof Node) || root.contains(target)) {
            return;
        }
        setOpen(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
            setOpen(false);
            btn.focus();
        }
    });

    // Close when the app-update bell opens.
    document.getElementById('update-notify-btn')?.addEventListener('click', () => {
        if (!panel.hidden) {
            setOpen(false);
        }
    });

    if (searchInput) {
        searchInput.addEventListener('input', applySearch);
        searchInput.addEventListener('search', applySearch);
    }
})();
