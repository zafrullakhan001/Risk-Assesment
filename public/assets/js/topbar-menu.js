(function () {
    const navKey = 'ra-nav';
    const root = document.documentElement;
    const closeTimers = new WeakMap();

    const getNavMode = () => {
        const mode = localStorage.getItem(navKey) || 'menu';
        return mode === 'bar' ? 'bar' : 'menu';
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

    document.addEventListener('DOMContentLoaded', () => {
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
                if (target.closest('a.home-link')) {
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
