(function () {
    const navKey = 'ra-nav';
    const LAST_DEST_KEY = 'riskregister_last_nav';
    const root = document.documentElement;
    const closeTimers = new WeakMap();

    const getNavMode = () => {
        const mode = localStorage.getItem(navKey) || 'menu';
        return mode === 'bar' ? 'bar' : 'menu';
    };

    const destFromLocation = (href) => {
        try {
            const url = new URL(href, window.location.href);
            const path = url.pathname.replace(/\\/g, '/').toLowerCase();
            const file = path.split('/').pop() || '';
            const view = (url.searchParams.get('view') || '').toLowerCase();
            const hash = (url.hash || '').toLowerCase();
            if (path.includes('/ticket-dossier')) return 'ticket';
            if (file === 'templates.php') return 'templates';
            if (file === 'help.php') return 'help';
            if (path.includes('/admin/')) return 'admin';
            if (file === 'sharepoint.php' || path.endsWith('/sharepoint.php')) {
                if (view === 'catalog') return 'catalogs';
                if (view === 'owners') return 'owners';
                if (view === 'heatmap') return 'storage';
                return 'sharepoint';
            }
            if (file === 'index.php' || file === '' || path.endsWith('/public/') || path.endsWith('/public')) {
                if (hash.includes('upload')) return 'upload';
                return 'find';
            }
            return '';
        } catch {
            return '';
        }
    };

    const sanitizeStoredPath = (value) => {
        const raw = String(value || '').trim();
        if (!raw || raw.length > 2000) return '';
        try {
            const url = new URL(raw, window.location.href);
            if (url.origin !== window.location.origin) return '';
            if (url.protocol !== 'http:' && url.protocol !== 'https:') return '';
            ['catalog_shared', 'owners_shared', 'emailed', 'deleted', 'deleted_bulk', 'shared', 'ok', 'flash', 'csrf'].forEach((key) => {
                url.searchParams.delete(key);
            });
            const path = `${url.pathname}${url.search}${url.hash}`;
            const lower = path.toLowerCase();
            if (
                lower.includes('login.php')
                || lower.includes('logout.php')
                || lower.includes('catalog-share.php')
                || lower.includes('javascript:')
            ) {
                return '';
            }
            return path;
        } catch {
            return '';
        }
    };

    const readLastDest = () => {
        try {
            const raw = JSON.parse(localStorage.getItem(LAST_DEST_KEY) || '{}');
            return raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
        } catch {
            return {};
        }
    };

    const rewriteMenuHrefs = () => {
        const stored = readLastDest();
        document.querySelectorAll('a.home-link[href], a.topbar-menu-account-card[href]').forEach((link) => {
            const dest = (link.getAttribute('data-nav-dest') || destFromLocation(link.getAttribute('href') || '')).trim();
            if (!dest || dest === 'help') return;
            const saved = sanitizeStoredPath(stored[dest]);
            if (!saved) return;
            link.setAttribute('href', saved);
        });
    };

    const saveCurrentPage = () => {
        if (document.getElementById('sharepoint-search')?.getAttribute('data-public') === '1') return;
        const dest = destFromLocation(window.location.href);
        if (!dest) return;
        const path = sanitizeStoredPath(`${window.location.pathname}${window.location.search}${window.location.hash}`);
        if (!path) return;
        const stored = readLastDest();
        stored[dest] = path;
        try {
            localStorage.setItem(LAST_DEST_KEY, JSON.stringify(stored));
        } catch {
            /* ignore quota */
        }
    };

    const rememberCurrent = () => {
        saveCurrentPage();
        rewriteMenuHrefs();
    };

    window.RiskRegisterNavMemory = {
        rememberCurrent,
        destFromLocation,
    };

    const syncModeButtons = () => {
        const active = getNavMode();
        document.querySelectorAll('[data-nav-set]').forEach((button) => {
            const isActive = button.getAttribute('data-nav-set') === active;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    };

    const closeForeignPanels = () => {
        const sharedBtn = document.getElementById('shared-access-btn');
        const sharedPanel = document.getElementById('shared-access-panel');
        if (sharedBtn && sharedPanel && !sharedPanel.hidden) {
            sharedPanel.hidden = true;
            sharedBtn.setAttribute('aria-expanded', 'false');
        }
        const notifyBtn = document.getElementById('update-notify-btn');
        const notifyPanel = document.getElementById('update-notify-panel');
        if (notifyBtn && notifyPanel && !notifyPanel.hidden) {
            notifyPanel.hidden = true;
            notifyBtn.setAttribute('aria-expanded', 'false');
        }
    };

    const clearCloseTimer = (menuRoot) => {
        const timer = closeTimers.get(menuRoot);
        if (timer) {
            window.clearTimeout(timer);
            closeTimers.delete(menuRoot);
        }
    };

    const closeMenu = (menuRoot, immediate) => {
        const btn = menuRoot.querySelector('#topbar-menu-btn');
        const panel = menuRoot.querySelector('#topbar-menu-panel');
        if (!btn || !panel) {
            return;
        }
        if (getNavMode() === 'bar') {
            clearCloseTimer(menuRoot);
            panel.hidden = false;
            btn.setAttribute('aria-expanded', 'false');
            menuRoot.classList.remove('is-open');
            return;
        }

        btn.setAttribute('aria-expanded', 'false');
        if (!menuRoot.classList.contains('is-open')) {
            panel.hidden = true;
            return;
        }

        menuRoot.classList.remove('is-open');
        clearCloseTimer(menuRoot);

        if (immediate || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            panel.hidden = true;
            return;
        }

        const finish = () => {
            if (!menuRoot.classList.contains('is-open')) {
                panel.hidden = true;
            }
            clearCloseTimer(menuRoot);
        };

        const onEnd = (event) => {
            if (event.target !== panel || event.propertyName !== 'opacity') {
                return;
            }
            panel.removeEventListener('transitionend', onEnd);
            finish();
        };

        panel.addEventListener('transitionend', onEnd);
        closeTimers.set(menuRoot, window.setTimeout(finish, 280));
    };

    const openMenu = (menuRoot) => {
        const btn = menuRoot.querySelector('#topbar-menu-btn');
        const panel = menuRoot.querySelector('#topbar-menu-panel');
        if (!btn || !panel || getNavMode() === 'bar') {
            return;
        }
        clearCloseTimer(menuRoot);
        closeForeignPanels();
        rememberCurrent();
        panel.hidden = false;
        // Force a reflow so the fade-in transition runs from opacity 0.
        void panel.offsetWidth;
        btn.setAttribute('aria-expanded', 'true');
        menuRoot.classList.add('is-open');
    };

    const applyNavMode = (mode) => {
        const next = mode === 'bar' ? 'bar' : 'menu';
        localStorage.setItem(navKey, next);
        root.setAttribute('data-nav-mode', next);
        syncModeButtons();

        document.querySelectorAll('.topbar-menu').forEach((menuRoot) => {
            const panel = menuRoot.querySelector('#topbar-menu-panel');
            const btn = menuRoot.querySelector('#topbar-menu-btn');
            if (!panel || !btn) {
                return;
            }
            clearCloseTimer(menuRoot);
            if (next === 'bar') {
                panel.hidden = false;
                btn.setAttribute('aria-expanded', 'false');
                menuRoot.classList.remove('is-open');
            } else {
                panel.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
                menuRoot.classList.remove('is-open');
            }
        });

        if (typeof window.syncStickyOffsets === 'function') {
            window.requestAnimationFrame(window.syncStickyOffsets);
        }
    };

    const organizeMenuGroups = (menuRoot) => {
        const source = menuRoot.querySelector('[data-topbar-menu-source]');
        if (!source || source.dataset.organized === '1') {
            return;
        }

        const allowed = { risk: 1, sharepoint: 1, storage: 1, ticket: 1, misc: 1 };
        const items = Array.from(source.children);
        items.forEach((item) => {
            if (!(item instanceof Element)) {
                return;
            }
            let group = (item.getAttribute('data-menu-group') || '').trim();
            if (!allowed[group]) {
                const href = (item.getAttribute('href') || '').toLowerCase();
                const text = (item.textContent || '').toLowerCase();
                if (href.includes('view=heatmap') || text.includes('storage heatmap') || text === 'storage') {
                    group = 'storage';
                } else if (href.includes('ticket-dossier') || text.includes('ticket dossier') || text.includes('export json') || text.includes('all projects')) {
                    group = 'ticket';
                } else if (href.includes('sharepoint') || text.includes('catalog') || text.includes('owners')) {
                    group = 'sharepoint';
                } else if (
                    href.includes('templates')
                    || href.includes('#upload')
                    || href.includes('#find-projects')
                    || text.includes('find')
                    || text.includes('upload')
                    || text.includes('template')
                ) {
                    group = 'risk';
                } else {
                    group = 'misc';
                }
                item.setAttribute('data-menu-group', group);
            }

            const bucket = menuRoot.querySelector(`[data-menu-group-items="${group}"]`);
            if (bucket) {
                bucket.appendChild(item);
            }
        });

        menuRoot.querySelectorAll('[data-menu-group-panel]').forEach((panel) => {
            const group = panel.getAttribute('data-menu-group-panel') || '';
            const bucket = menuRoot.querySelector(`[data-menu-group-items="${group}"]`);
            const hasItems = !!(bucket && bucket.children.length > 0);
            panel.hidden = !hasItems;
        });

        const sourceSection = source.closest('.topbar-menu-nav-source');
        if (sourceSection) {
            sourceSection.hidden = true;
        }
        source.dataset.organized = '1';
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.topbar-menu').forEach((menuRoot) => {
            organizeMenuGroups(menuRoot);
        });

        rememberCurrent();
        window.addEventListener('hashchange', rememberCurrent);
        window.addEventListener('pagehide', saveCurrentPage);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') saveCurrentPage();
        });

        applyNavMode(getNavMode());

        document.querySelectorAll('[data-nav-set]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                applyNavMode(button.getAttribute('data-nav-set') || 'menu');
            });
        });

        document.querySelectorAll('.topbar-menu').forEach((menuRoot) => {
            const btn = menuRoot.querySelector('#topbar-menu-btn');
            const closeBtn = menuRoot.querySelector('#topbar-menu-close');
            const panel = menuRoot.querySelector('#topbar-menu-panel');
            if (!btn || !panel) {
                return;
            }

            btn.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (getNavMode() === 'bar') {
                    return;
                }
                if (menuRoot.classList.contains('is-open')) {
                    closeMenu(menuRoot);
                } else {
                    openMenu(menuRoot);
                }
            });

            if (closeBtn) {
                closeBtn.addEventListener('click', (event) => {
                    event.preventDefault();
                    closeMenu(menuRoot);
                    btn.focus();
                });
            }

            panel.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }
                if (target.closest('a.home-link, a.topbar-menu-account-card, a.topbar-menu-help-icon')) {
                    closeMenu(menuRoot);
                }
            });
        });

        document.addEventListener('click', (event) => {
            if (getNavMode() === 'bar') {
                return;
            }
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            document.querySelectorAll('.topbar-menu.is-open').forEach((menuRoot) => {
                if (!menuRoot.contains(target)) {
                    closeMenu(menuRoot);
                }
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || getNavMode() === 'bar') {
                return;
            }
            document.querySelectorAll('.topbar-menu.is-open').forEach((menuRoot) => {
                closeMenu(menuRoot);
            });
        });

        document.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            if (target.closest('#shared-access-btn, #update-notify-btn')) {
                document.querySelectorAll('.topbar-menu.is-open').forEach((menuRoot) => {
                    closeMenu(menuRoot, true);
                });
            }
        }, true);
    });
})();
