(function () {
    'use strict';

    var STORAGE_KEY = 'ticket_dossier_search_dock_pref_v2';
    var MIN_WIDTH = 280;
    var MIN_HEIGHT = 280;
    var container = document.getElementById('global-search');
    if (!container) {
        return;
    }

    var collapseBtn = document.getElementById('search-collapse');
    var closeBtn = document.getElementById('search-dock-close');
    var expandBtn = document.getElementById('search-rail-expand');
    var reopenBtn = document.getElementById('search-reopen');
    var prevBtn = document.getElementById('search-prev');
    var nextBtn = document.getElementById('search-next');
    var statusEl = document.getElementById('search-nav-status');
    var searchInput = document.getElementById('global-search-input');
    var dockHeader = document.getElementById('search-dock-header');
    var dragHandle = document.getElementById('search-dock-drag');
    var resizeHandle = document.getElementById('search-resize-handle');

    var state = {
        mode: 'inline',
        collapsed: false,
        matchCount: 0,
        activeIndex: -1,
        query: '',
        showReopen: false,
        moved: false,
        geom: null
    };

    var drag = null;
    var resize = null;

    function isDesktopDock() {
        return window.matchMedia('(min-width: 901px)').matches;
    }

    function clamp(n, min, max) {
        return Math.min(max, Math.max(min, n));
    }

    function readPref() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);
            if (!raw) {
                // Migrate old key once.
                raw = window.localStorage.getItem('ticket_dossier_search_dock_pref_v1');
            }
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object') return null;
            var geom = null;
            if (parsed.geom && typeof parsed.geom === 'object') {
                geom = {
                    top: Number(parsed.geom.top) || 0,
                    left: Number(parsed.geom.left) || 0,
                    width: Number(parsed.geom.width) || 360,
                    height: Number(parsed.geom.height) || 480
                };
            }
            return {
                preferDocked: !!parsed.preferDocked,
                collapsed: !!parsed.collapsed,
                moved: !!parsed.moved,
                geom: geom
            };
        } catch (err) {
            return null;
        }
    }

    function writePref() {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
                preferDocked: state.mode === 'docked',
                collapsed: state.collapsed,
                moved: state.moved,
                geom: state.geom
            }));
        } catch (err) {
            // Ignore storage failures.
        }
    }

    function defaultGeom() {
        var topbar = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--topbar-sticky-height'), 10);
        if (!topbar || isNaN(topbar)) topbar = 64;
        var width = Math.min(380, window.innerWidth - 36);
        var height = Math.max(MIN_HEIGHT, window.innerHeight - topbar - 28);
        return {
            top: topbar + 12,
            left: Math.max(12, window.innerWidth - width - 14),
            width: width,
            height: height
        };
    }

    function applyGeometry() {
        if (state.mode !== 'docked' || !isDesktopDock()) {
            container.style.top = '';
            container.style.left = '';
            container.style.right = '';
            container.style.bottom = '';
            container.style.width = '';
            container.style.height = '';
            return;
        }

        if (state.collapsed) {
            var rail = state.geom || defaultGeom();
            container.style.top = rail.top + 'px';
            container.style.left = rail.left + 'px';
            container.style.right = 'auto';
            container.style.bottom = 'auto';
            container.style.width = '56px';
            container.style.height = 'auto';
            return;
        }

        var geom = state.geom || defaultGeom();
        var maxW = window.innerWidth - 24;
        var maxH = window.innerHeight - 24;
        var width = clamp(geom.width, MIN_WIDTH, maxW);
        var height = clamp(geom.height, MIN_HEIGHT, maxH);
        var left = clamp(geom.left, 8, window.innerWidth - width - 8);
        var top = clamp(geom.top, 8, window.innerHeight - height - 8);

        state.geom = { top: top, left: left, width: width, height: height };
        container.style.top = top + 'px';
        container.style.left = left + 'px';
        container.style.right = 'auto';
        container.style.bottom = 'auto';
        container.style.width = width + 'px';
        container.style.height = height + 'px';
    }

    function clearGeometryStyles() {
        container.style.top = '';
        container.style.left = '';
        container.style.right = '';
        container.style.bottom = '';
        container.style.width = '';
        container.style.height = '';
    }

    function applyBodyClasses() {
        if (!document.body) return;
        document.body.classList.toggle('dossier-search-docked', state.mode === 'docked');
        document.body.classList.toggle('dossier-search-collapsed', state.mode === 'docked' && state.collapsed);
        document.body.classList.toggle('dossier-search-moved', state.mode === 'docked' && state.moved);
    }

    function updateChrome() {
        container.setAttribute('data-mode', state.mode);
        container.setAttribute('data-collapsed', state.collapsed ? '1' : '0');
        applyBodyClasses();

        if (state.mode === 'docked' && isDesktopDock()) {
            applyGeometry();
        } else if (state.mode !== 'docked') {
            clearGeometryStyles();
        } else {
            clearGeometryStyles();
        }

        if (collapseBtn) {
            collapseBtn.setAttribute('aria-pressed', state.collapsed ? 'true' : 'false');
            collapseBtn.title = state.collapsed ? 'Expand search' : 'Collapse search';
            collapseBtn.setAttribute('aria-label', state.collapsed ? 'Expand search panel' : 'Collapse search panel');
            collapseBtn.textContent = state.collapsed ? '⟵' : '⟷';
        }

        if (expandBtn) {
            expandBtn.hidden = !(state.mode === 'docked' && state.collapsed);
        }

        if (reopenBtn) {
            reopenBtn.classList.toggle('hidden', !(state.showReopen && state.mode === 'inline'));
        }

        updateNavControls();
    }

    function updateNavControls() {
        var hasMatches = state.matchCount > 0;
        if (prevBtn) prevBtn.disabled = !hasMatches;
        if (nextBtn) nextBtn.disabled = !hasMatches;

        if (!statusEl) return;
        if (state.query && state.matchCount === 0) {
            statusEl.textContent = 'No matches';
            return;
        }
        if (state.matchCount > 0 && state.activeIndex >= 0) {
            statusEl.textContent = (state.activeIndex + 1) + ' / ' + state.matchCount;
            return;
        }
        if (state.matchCount > 0) {
            statusEl.textContent = state.matchCount + ' match' + (state.matchCount === 1 ? '' : 'es');
            return;
        }
        statusEl.textContent = state.mode === 'docked' ? 'Jump or search' : '';
    }

    function ensureGeom() {
        if (!state.geom) {
            state.geom = defaultGeom();
        }
        return state.geom;
    }

    function openDock(options) {
        options = options || {};
        state.mode = 'docked';
        state.showReopen = false;
        if (typeof options.collapsed === 'boolean') {
            state.collapsed = options.collapsed;
        }
        if (isDesktopDock() && !state.geom) {
            state.geom = defaultGeom();
        }
        updateChrome();
        writePref();
        if (!state.collapsed && searchInput && options.focus !== false) {
            window.requestAnimationFrame(function () {
                searchInput.focus({ preventScroll: true });
            });
        }
    }

    function closeDock() {
        state.mode = 'inline';
        state.collapsed = false;
        // Fully dismiss — do not fall back to the inline results dropdown.
        state.showReopen = false;
        state.matchCount = 0;
        state.activeIndex = -1;
        state.query = '';
        updateChrome();
        writePref();
        var api = navApi();
        if (api && typeof api.clear === 'function') {
            api.clear({ keepFocus: false });
        } else if (searchInput) {
            searchInput.value = '';
        }
    }

    function setCollapsed(collapsed) {
        if (state.mode !== 'docked') return;
        state.collapsed = !!collapsed;
        updateChrome();
        writePref();
        if (!state.collapsed && searchInput) {
            window.requestAnimationFrame(function () {
                searchInput.focus({ preventScroll: true });
            });
        }
    }

    function sync(payload) {
        payload = payload || {};
        if (typeof payload.matchCount === 'number') state.matchCount = payload.matchCount;
        if (typeof payload.activeIndex === 'number') state.activeIndex = payload.activeIndex;
        if (typeof payload.query === 'string') state.query = payload.query;
        updateNavControls();

        if (payload.open && state.mode !== 'docked') {
            openDock({ focus: !!payload.focus, collapsed: false });
        }
    }

    function navApi() {
        return window.TicketDossierSearchNav || null;
    }

    function goPrev() {
        var api = navApi();
        if (api && typeof api.prev === 'function') api.prev();
    }

    function goNext() {
        var api = navApi();
        if (api && typeof api.next === 'function') api.next();
    }

    function pointerEvent(e) {
        if (e.touches && e.touches[0]) {
            return { x: e.touches[0].clientX, y: e.touches[0].clientY };
        }
        return { x: e.clientX, y: e.clientY };
    }

    function startDrag(e) {
        if (state.mode !== 'docked' || state.collapsed || !isDesktopDock()) return;
        if (e.target && e.target.closest && e.target.closest('.search-dock-actions')) return;
        var pt = pointerEvent(e);
        var geom = ensureGeom();
        applyGeometry();
        drag = {
            startX: pt.x,
            startY: pt.y,
            origLeft: geom.left,
            origTop: geom.top
        };
        document.body.classList.add('dossier-search-dragging');
        e.preventDefault();
    }

    function startResize(e) {
        if (state.mode !== 'docked' || state.collapsed || !isDesktopDock()) return;
        var pt = pointerEvent(e);
        var geom = ensureGeom();
        applyGeometry();
        resize = {
            startX: pt.x,
            startY: pt.y,
            origWidth: geom.width,
            origHeight: geom.height,
            origLeft: geom.left,
            origTop: geom.top
        };
        document.body.classList.add('dossier-search-resizing');
        e.preventDefault();
        e.stopPropagation();
    }

    function onPointerMove(e) {
        var pt = pointerEvent(e);
        if (drag) {
            var geom = ensureGeom();
            var width = geom.width;
            var height = geom.height;
            geom.left = clamp(drag.origLeft + (pt.x - drag.startX), 8, window.innerWidth - width - 8);
            geom.top = clamp(drag.origTop + (pt.y - drag.startY), 8, window.innerHeight - height - 8);
            state.moved = true;
            applyGeometry();
            applyBodyClasses();
            e.preventDefault();
            return;
        }
        if (resize) {
            var next = ensureGeom();
            var maxW = window.innerWidth - next.left - 8;
            var maxH = window.innerHeight - next.top - 8;
            next.width = clamp(resize.origWidth + (pt.x - resize.startX), MIN_WIDTH, maxW);
            next.height = clamp(resize.origHeight + (pt.y - resize.startY), MIN_HEIGHT, maxH);
            state.moved = true;
            applyGeometry();
            applyBodyClasses();
            e.preventDefault();
        }
    }

    function endPointer() {
        if (!drag && !resize) return;
        drag = null;
        resize = null;
        document.body.classList.remove('dossier-search-dragging', 'dossier-search-resizing');
        writePref();
    }

    if (dragHandle) {
        dragHandle.addEventListener('mousedown', startDrag);
        dragHandle.addEventListener('touchstart', startDrag, { passive: false });
    } else if (dockHeader) {
        dockHeader.addEventListener('mousedown', startDrag);
        dockHeader.addEventListener('touchstart', startDrag, { passive: false });
    }

    if (resizeHandle) {
        resizeHandle.addEventListener('mousedown', startResize);
        resizeHandle.addEventListener('touchstart', startResize, { passive: false });
    }

    document.addEventListener('mousemove', onPointerMove);
    document.addEventListener('touchmove', onPointerMove, { passive: false });
    document.addEventListener('mouseup', endPointer);
    document.addEventListener('touchend', endPointer);

    window.addEventListener('resize', function () {
        if (state.mode === 'docked' && isDesktopDock()) {
            applyGeometry();
        }
    });

    if (collapseBtn) {
        collapseBtn.addEventListener('click', function () {
            if (state.mode !== 'docked') {
                openDock({ collapsed: false });
                return;
            }
            setCollapsed(!state.collapsed);
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            closeDock();
        });
    }

    if (expandBtn) {
        expandBtn.addEventListener('click', function () {
            setCollapsed(false);
        });
    }

    if (reopenBtn) {
        reopenBtn.addEventListener('click', function () {
            openDock({ focus: true, collapsed: false });
        });
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            if (state.mode !== 'docked') openDock({ focus: false });
            goPrev();
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            if (state.mode !== 'docked') openDock({ focus: false });
            goNext();
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && state.mode === 'docked') {
            var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
            if (tag === 'input' || tag === 'textarea') {
                if (searchInput && document.activeElement === searchInput && searchInput.value) {
                    return;
                }
            }
            if (state.collapsed) {
                closeDock();
            } else {
                setCollapsed(true);
            }
            e.preventDefault();
            return;
        }

        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && state.mode === 'docked') {
            if (e.shiftKey) goPrev();
            else goNext();
            e.preventDefault();
        }
    });

    var pref = readPref();
    if (pref) {
        state.collapsed = !!pref.collapsed;
        state.moved = !!pref.moved;
        if (pref.geom) state.geom = pref.geom;
    }

    updateChrome();

    window.TicketDossierFloatingSearch = {
        open: openDock,
        close: closeDock,
        collapse: function () { setCollapsed(true); },
        expand: function () { setCollapsed(false); },
        sync: sync,
        isOpen: function () { return state.mode === 'docked'; },
        isCollapsed: function () { return state.collapsed; }
    };
})();
