/**
 * LinkNest-style animation picker for dossier search result cards.
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'ticket_dossier_search_animation_v1';
    var DECOR_KEY = 'ticket_dossier_search_decoration_v1';
    var FALLBACK_ANIMATION = 'slide-down';
    var catalog = null;
    var selectedAnimation = FALLBACK_ANIMATION;
    var selectedDecoration = 'none';
    var activeTab = 'animations';
    var searchQuery = '';
    var panelEl = null;
    var backdropEl = null;
    var previewKey = 0;
    var dragState = null;
    var isOpen = false;
    var changeListeners = [];

    function api() {
        return global.TicketDossierSearchAnimations || null;
    }

    function preferReducedMotion() {
        return !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function readStored(key, fallback) {
        try {
            var raw = global.localStorage.getItem(key);
            return raw || fallback;
        } catch (err) {
            return fallback;
        }
    }

    function writeStored(key, value) {
        try {
            global.localStorage.setItem(key, value);
        } catch (err) {
            // Ignore quota / private mode.
        }
    }

    function normalizeAnimation(id) {
        var cat = api();
        var fallback = (cat && cat.DEFAULT_SEARCH_ANIMATION) || FALLBACK_ANIMATION;
        if (!id || id === 'inherit') return fallback;
        // "none" is allowed only when the user explicitly picks it — still valid.
        if (!cat) return id === 'none' ? 'none' : fallback;
        var found = cat.findAnimation(id);
        if (found && found.id) return found.id;
        return fallback;
    }

    function normalizeDecoration(id) {
        var cat = api();
        if (!cat) return 'none';
        var found = cat.findDecoration(id);
        return found && found.id ? found.id : 'none';
    }

    function getAnimationClass(id) {
        var cat = api();
        return cat ? cat.getAnimationClass(id || selectedAnimation) : '';
    }

    function getDecorationClass(id) {
        var cat = api();
        return cat ? cat.getDecorationClass(id || selectedDecoration) : '';
    }

    function notifyChange() {
        changeListeners.forEach(function (fn) {
            try { fn(selectedAnimation, selectedDecoration); } catch (err) { /* ignore */ }
        });
        try {
            global.dispatchEvent(new CustomEvent('ticket-dossier-search-animation-change', {
                detail: { animation: selectedAnimation, decoration: selectedDecoration }
            }));
        } catch (err) {
            // Older browsers.
        }
    }

    function setAnimation(id, silent) {
        selectedAnimation = normalizeAnimation(id);
        writeStored(STORAGE_KEY, selectedAnimation);
        updateToggleLabel();
        if (!silent) notifyChange();
    }

    function setDecoration(id, silent) {
        selectedDecoration = normalizeDecoration(id);
        writeStored(DECOR_KEY, selectedDecoration);
        if (!silent) notifyChange();
    }

    function updateToggleLabel() {
        var btn = document.getElementById('search-anim-toggle');
        if (!btn) return;
        var cat = api();
        var opt = cat ? cat.findAnimation(selectedAnimation) : null;
        var label = opt && opt.name ? opt.name : 'Anim';
        btn.textContent = '✨ ' + label;
        btn.title = 'Search result entrance animation — currently ' + label;
        btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        btn.classList.toggle('is-active', isOpen);
    }

    function filteredOptions() {
        var cat = api();
        if (!cat) return [];
        var q = String(searchQuery || '').trim().toLowerCase();
        var source = activeTab === 'decorations' ? cat.DECORATION_OPTIONS : cat.ANIMATION_OPTIONS;
        var out = [];
        source.forEach(function (opt, index) {
            var hay = (opt.name + ' ' + opt.description + ' #' + (index + 1)).toLowerCase();
            if (q && hay.indexOf(q) === -1) return;
            out.push({
                id: opt.id,
                name: opt.name,
                description: opt.description,
                icon: opt.icon,
                index: index + 1
            });
        });
        return out;
    }

    function ensureDom() {
        if (panelEl) return;
        backdropEl = document.createElement('div');
        backdropEl.className = 'search-anim-backdrop';
        backdropEl.hidden = true;
        backdropEl.addEventListener('click', function (e) {
            e.preventDefault();
            close();
        });

        panelEl = document.createElement('div');
        panelEl.className = 'search-anim-panel';
        panelEl.hidden = true;
        panelEl.setAttribute('role', 'dialog');
        panelEl.setAttribute('aria-modal', 'true');
        panelEl.setAttribute('aria-label', 'Search result animations');
        panelEl.innerHTML = [
            '<div class="search-anim-chrome" id="search-anim-drag" title="Drag to move">',
            '  <div class="search-anim-chrome-title">',
            '    <span class="search-anim-chrome-label">Global Visual Engine</span>',
            '    <span class="search-anim-chrome-hint">Drag to move · Affects search matches</span>',
            '  </div>',
            '  <button type="button" class="search-anim-close" id="search-anim-close" aria-label="Close animation picker" title="Close">×</button>',
            '</div>',
            '<div class="search-anim-tabs">',
            '  <button type="button" class="search-anim-tab is-active" data-tab="animations">✨ Animations</button>',
            '  <button type="button" class="search-anim-tab" data-tab="decorations">🎄 Festive</button>',
            '</div>',
            '<div class="search-anim-header">',
            '  <div class="search-anim-search-wrap">',
            '    <span class="search-anim-search-icon" aria-hidden="true">🔍</span>',
            '    <input type="search" class="search-anim-search" id="search-anim-query" placeholder="Search animations..." autocomplete="off">',
            '  </div>',
            '  <div class="search-anim-preview">',
            '    <div class="search-anim-preview-bar">',
            '      <span class="search-anim-preview-label">Link preview</span>',
            '      <button type="button" class="search-anim-replay" id="search-anim-replay">Replay</button>',
            '    </div>',
            '    <div id="search-anim-preview-host"></div>',
            '  </div>',
            '</div>',
            '<div class="search-anim-grid-wrap"><div class="search-anim-grid" id="search-anim-grid"></div></div>',
            '<div class="search-anim-footer">',
            '  <button type="button" class="search-anim-done" id="search-anim-done">Done</button>',
            '</div>'
        ].join('');

        document.body.appendChild(backdropEl);
        document.body.appendChild(panelEl);

        panelEl.addEventListener('mousedown', function (e) {
            e.stopPropagation();
        });
        panelEl.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        panelEl.querySelectorAll('.search-anim-tab').forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                activeTab = tab.getAttribute('data-tab') === 'decorations' ? 'decorations' : 'animations';
                panelEl.querySelectorAll('.search-anim-tab').forEach(function (t) {
                    t.classList.toggle('is-active', t === tab);
                });
                var input = document.getElementById('search-anim-query');
                if (input) {
                    input.placeholder = activeTab === 'decorations' ? 'Search decorations...' : 'Search animations...';
                }
                renderGrid();
                renderPreview();
            });
        });

        var input = document.getElementById('search-anim-query');
        if (input) {
            input.addEventListener('input', function () {
                searchQuery = input.value || '';
                renderGrid();
            });
            input.addEventListener('mousedown', function (e) { e.stopPropagation(); });
        }

        document.getElementById('search-anim-replay').addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            replayPreview();
        });

        function bindClose(el) {
            if (!el) return;
            el.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                close();
            });
        }
        bindClose(document.getElementById('search-anim-done'));
        bindClose(document.getElementById('search-anim-close'));

        var drag = document.getElementById('search-anim-drag');
        function onPointerDown(e) {
            if (e.target.closest('button, input, a')) return;
            if (e.button != null && e.button !== 0) return;
            e.preventDefault();
            e.stopPropagation();
            var rect = panelEl.getBoundingClientRect();
            var point = e.touches && e.touches[0] ? e.touches[0] : e;
            dragState = {
                startX: point.clientX,
                startY: point.clientY,
                origLeft: rect.left,
                origTop: rect.top
            };
            drag.classList.add('is-dragging');
            if (drag.setPointerCapture && e.pointerId != null) {
                try { drag.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
            }
        }
        function onPointerMove(e) {
            if (!dragState) return;
            var point = e.touches && e.touches[0] ? e.touches[0] : e;
            var left = dragState.origLeft + (point.clientX - dragState.startX);
            var top = dragState.origTop + (point.clientY - dragState.startY);
            left = Math.max(8, Math.min(left, window.innerWidth - panelEl.offsetWidth - 8));
            top = Math.max(8, Math.min(top, window.innerHeight - 80));
            panelEl.style.left = left + 'px';
            panelEl.style.top = top + 'px';
        }
        function onPointerUp() {
            if (!dragState) return;
            dragState = null;
            drag.classList.remove('is-dragging');
        }
        if (window.PointerEvent) {
            drag.addEventListener('pointerdown', onPointerDown);
            drag.addEventListener('pointermove', onPointerMove);
            drag.addEventListener('pointerup', onPointerUp);
            drag.addEventListener('pointercancel', onPointerUp);
        } else {
            drag.addEventListener('mousedown', onPointerDown);
            document.addEventListener('mousemove', onPointerMove);
            document.addEventListener('mouseup', onPointerUp);
            drag.addEventListener('touchstart', onPointerDown, { passive: false });
            document.addEventListener('touchmove', onPointerMove, { passive: false });
            document.addEventListener('touchend', onPointerUp);
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) {
                e.preventDefault();
                close();
            }
        });
    }

    function renderPreview() {
        var host = document.getElementById('search-anim-preview-host');
        if (!host) return;
        var cat = api();
        var opt = cat ? cat.findAnimation(selectedAnimation) : { icon: '✨', description: '' };
        var decor = getDecorationClass(selectedDecoration);
        var anim = getAnimationClass(selectedAnimation);
        previewKey += 1;
        host.innerHTML = '';
        var card = document.createElement('div');
        card.className = ('search-anim-preview-card linknest-tile-animated ' + anim + ' ' + decor).trim();
        card.setAttribute('data-preview-key', String(previewKey));
        card.innerHTML = [
            '<div class="search-anim-preview-icon" aria-hidden="true">' + (opt.icon || '✨') + '</div>',
            '<div class="search-anim-preview-text">',
            '  <div class="search-anim-preview-name">Sample match</div>',
            '  <div class="search-anim-preview-meta">' +
                (selectedAnimation === 'none' ? (opt.description || 'No animation') : 'dossier search result') +
            '</div>',
            '</div>'
        ].join('');
        host.appendChild(card);
    }

    function replayPreview() {
        renderPreview();
        // Also re-trigger live result cards if present.
        var list = document.querySelector('.search-match-list');
        if (!list) return;
        list.querySelectorAll('.search-match-item').forEach(function (el) {
            el.style.animation = 'none';
        });
        void list.offsetWidth;
        list.querySelectorAll('.search-match-item').forEach(function (el) {
            el.style.animation = '';
        });
    }

    function renderGrid() {
        var grid = document.getElementById('search-anim-grid');
        if (!grid) return;
        var selectedId = activeTab === 'decorations' ? selectedDecoration : selectedAnimation;
        var opts = filteredOptions();
        var html = '';
        opts.forEach(function (opt) {
            html += '<button type="button" class="search-anim-option' +
                (opt.id === selectedId ? ' is-selected' : '') +
                '" data-id="' + opt.id.replace(/"/g, '') + '">';
            html += '<span class="search-anim-option-index">#' + opt.index + '</span>';
            html += '<div class="search-anim-option-row">';
            html += '<span class="search-anim-option-icon" aria-hidden="true">' + opt.icon + '</span>';
            html += '<div><div class="search-anim-option-name">' + escapeHtml(opt.name) + '</div>';
            html += '<div class="search-anim-option-desc">' + escapeHtml(opt.description) + '</div></div>';
            html += '</div></button>';
        });
        grid.innerHTML = html || '<p class="search-anim-option-desc">No matches.</p>';
        grid.querySelectorAll('.search-anim-option').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var id = btn.getAttribute('data-id') || 'none';
                if (activeTab === 'decorations') {
                    setDecoration(id);
                } else {
                    setAnimation(id);
                }
                renderGrid();
                renderPreview();
            });
        });

        var tabs = panelEl.querySelectorAll('.search-anim-tab');
        var cat = api();
        if (tabs[0] && cat) {
            tabs[0].textContent = '✨ Animations (' + cat.ANIMATION_OPTIONS.length + ')';
        }
        if (tabs[1] && cat) {
            tabs[1].textContent = '🎄 Festive (' + cat.DECORATION_OPTIONS.length + ')';
        }
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function positionPanel(anchor) {
        ensureDom();
        var width = Math.min(360, window.innerWidth - 32);
        var left = 16;
        var top = 72;
        if (anchor && anchor.getBoundingClientRect) {
            var r = anchor.getBoundingClientRect();
            left = Math.min(Math.max(8, r.right - width), window.innerWidth - width - 8);
            top = Math.min(r.bottom + 8, window.innerHeight - 120);
        }
        panelEl.style.width = width + 'px';
        panelEl.style.left = left + 'px';
        panelEl.style.top = top + 'px';
    }

    function open(anchor) {
        ensureDom();
        positionPanel(anchor || document.getElementById('search-anim-toggle'));
        isOpen = true;
        backdropEl.hidden = false;
        panelEl.hidden = false;
        searchQuery = '';
        var input = document.getElementById('search-anim-query');
        if (input) input.value = '';
        renderGrid();
        renderPreview();
        updateToggleLabel();
        if (input) {
            setTimeout(function () { input.focus(); }, 30);
        }
    }

    function close() {
        ensureDom();
        isOpen = false;
        dragState = null;
        if (panelEl) {
            panelEl.hidden = true;
            var drag = document.getElementById('search-anim-drag');
            if (drag) drag.classList.remove('is-dragging');
        }
        if (backdropEl) backdropEl.hidden = true;
        updateToggleLabel();
        var toggle = document.getElementById('search-anim-toggle');
        if (toggle) {
            try { toggle.focus(); } catch (err) { /* ignore */ }
        }
    }

    function toggle(anchor) {
        ensureDom();
        if (isOpen) close();
        else open(anchor);
    }

    function applyToMatchItems(root) {
        var host = root || document.getElementById('search-results');
        if (!host) return;
        applyEntranceAnimation(host.querySelectorAll('.search-match-item'));
    }

    function applyToJumpChips(root) {
        var host = root || document.getElementById('search-jumps-list');
        if (!host) return;
        applyEntranceAnimation(host.querySelectorAll('.search-jump-chip'));
    }

    function stripAnimClasses(el) {
        el.className = String(el.className || '')
            .split(/\s+/)
            .filter(function (c) {
                return c && c.indexOf('tile-anim-') !== 0 && c.indexOf('tile-decor-') !== 0 && c !== 'linknest-tile-animated';
            })
            .join(' ');
        el.style.animation = '';
        el.style.animationDelay = '';
        el.style.opacity = '';
        el.style.transform = '';
    }

    function applyEntranceAnimation(nodeList) {
        var items = nodeList || [];
        var useMotion = !preferReducedMotion();
        var animId = selectedAnimation;
        var animClass = useMotion ? getAnimationClass(animId) : '';
        var decorClass = useMotion ? getDecorationClass(selectedDecoration) : '';
        // Always keep chips/cards visible when there is no entrance animation.
        var shouldAnimate = !!(useMotion && animClass);

        Array.prototype.forEach.call(items, function (el, index) {
            stripAnimClasses(el);
            if (!shouldAnimate) {
                el.style.opacity = '1';
                el.style.transform = 'none';
                if (decorClass) el.classList.add(decorClass);
                return;
            }
            el.classList.add('linknest-tile-animated');
            el.classList.add(animClass);
            if (decorClass) el.classList.add(decorClass);
            el.style.animationDelay = (Math.min(index, 24) * 35) + 'ms';
        });
    }

    function init() {
        catalog = api();
        var stored = readStored(STORAGE_KEY, '');
        // First visit (or cleared storage): default to slide-down and persist it.
        if (!stored) {
            selectedAnimation = FALLBACK_ANIMATION;
            writeStored(STORAGE_KEY, selectedAnimation);
        } else {
            selectedAnimation = normalizeAnimation(stored);
            // Keep persistence in sync if an old/invalid id was stored.
            if (selectedAnimation !== stored) {
                writeStored(STORAGE_KEY, selectedAnimation);
            }
        }
        selectedDecoration = normalizeDecoration(readStored(DECOR_KEY, 'none'));

        var btn = document.getElementById('search-anim-toggle');
        if (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toggle(btn);
            });
        }
        updateToggleLabel();
    }

    global.TicketDossierSearchAnimPicker = {
        init: init,
        open: open,
        close: close,
        toggle: toggle,
        isOpen: function () { return isOpen; },
        getSelectedId: function () { return selectedAnimation; },
        getDecorationId: function () { return selectedDecoration; },
        getAnimationClass: function () { return getAnimationClass(selectedAnimation); },
        getDecorationClass: function () { return getDecorationClass(selectedDecoration); },
        applyToMatchItems: applyToMatchItems,
        applyToJumpChips: applyToJumpChips,
        onChange: function (fn) {
            if (typeof fn === 'function') changeListeners.push(fn);
        },
        setAnimation: setAnimation,
        setDecoration: setDecoration
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window);
