(function () {
    'use strict';

    // Theme / size / sticky offsets: shared public/assets/js/theme.js
    var maxFiles = 10;

    // ----- Drag & drop upload + content classify -----
    var form = document.getElementById('upload-form');
    var dropzone = document.getElementById('dropzone');
    var fileInput = document.getElementById('file-input');
    var browseBtn = document.getElementById('browse-files');
    var preview = document.getElementById('file-preview');
    var statusEl = document.getElementById('detect-status');
    var submitBtn = document.getElementById('submit-upload');
    var csrfInput = document.getElementById('csrf-token');
    var selectedFiles = [];

    function formatBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function syncInputFromSelection() {
        if (!fileInput || typeof DataTransfer === 'undefined') {
            return;
        }
        var dt = new DataTransfer();
        selectedFiles.forEach(function (entry) {
            dt.items.add(entry.file);
        });
        fileInput.files = dt.files;
        if (submitBtn) {
            var okCount = selectedFiles.filter(function (e) { return e.meta && e.meta.ok; }).length;
            submitBtn.disabled = okCount < 1;
        }
    }

    function renderPreview() {
        if (!preview) return;
        preview.innerHTML = '';
        selectedFiles.forEach(function (entry, index) {
            var meta = entry.meta || {};
            var li = document.createElement('li');
            li.className = 'file-preview-item' + (meta.ok ? '' : ' is-bad');

            var badge = document.createElement('span');
            badge.className = 'pill ' + (meta.ok ? (meta.kind === 'story' ? 'amber' : (meta.kind === 'task' ? 'gray' : 'teal')) : 'coral');
            badge.textContent = (meta.emoji || '📄') + ' ' + (meta.label || 'Unknown');

            var info = document.createElement('div');
            var name = document.createElement('div');
            name.className = 'fp-name';
            name.textContent = entry.file.name;
            var metaLine = document.createElement('div');
            metaLine.className = 'fp-meta';
            metaLine.textContent = formatBytes(entry.file.size)
                + (meta.message ? ' · ' + meta.message : '')
                + (meta.method ? ' · via ' + meta.method : '');
            info.appendChild(name);
            info.appendChild(metaLine);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'fp-remove';
            remove.setAttribute('aria-label', 'Remove ' + entry.file.name);
            remove.textContent = '×';
            remove.addEventListener('click', function () {
                selectedFiles.splice(index, 1);
                syncInputFromSelection();
                renderPreview();
                updateStatus();
            });

            li.appendChild(badge);
            li.appendChild(info);
            li.appendChild(remove);
            preview.appendChild(li);
        });
        updateStatus();
    }

    function updateStatus() {
        if (!statusEl) return;
        if (selectedFiles.length === 0) {
            statusEl.classList.add('hidden');
            statusEl.textContent = '';
            return;
        }
        var ok = selectedFiles.filter(function (e) { return e.meta && e.meta.ok; }).length;
        var bad = selectedFiles.length - ok;
        statusEl.classList.remove('hidden');
        statusEl.classList.toggle('is-error', ok === 0);
        statusEl.textContent = ok + ' recognized file' + (ok === 1 ? '' : 's')
            + (bad ? ' · ' + bad + ' unrecognized' : '')
            + ' · content detection preferred';
    }

    function classifyFiles(files) {
        if (!csrfInput) return Promise.resolve([]);
        var body = new FormData();
        body.append('csrf_token', csrfInput.value);
        files.forEach(function (file) {
            body.append('files[]', file, file.name);
        });
        if (statusEl) {
            statusEl.classList.remove('hidden', 'is-error');
            statusEl.textContent = 'Detecting file types from contents…';
        }
        return fetch('classify.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (!data || !data.ok || !Array.isArray(data.files)) {
                throw new Error((data && data.error) || 'Classification failed');
            }
            return data.files;
        });
    }

    function addFiles(fileList) {
        var incoming = Array.prototype.slice.call(fileList || []);
        if (incoming.length === 0) return;

        var room = maxFiles - selectedFiles.length;
        if (room <= 0) {
            if (statusEl) {
                statusEl.classList.remove('hidden');
                statusEl.classList.add('is-error');
                statusEl.textContent = 'Maximum ' + maxFiles + ' files per project.';
            }
            return;
        }

        var batch = incoming.slice(0, room).filter(function (file) {
            var name = (file.name || '').toLowerCase();
            return name.endsWith('.pdf') || name.endsWith('.json');
        });

        if (batch.length === 0) {
            if (statusEl) {
                statusEl.classList.remove('hidden');
                statusEl.classList.add('is-error');
                statusEl.textContent = 'Only PDF and JSON files are accepted.';
            }
            return;
        }

        // Deduplicate by name+size
        batch = batch.filter(function (file) {
            return !selectedFiles.some(function (existing) {
                return existing.file.name === file.name && existing.file.size === file.size;
            });
        });

        if (batch.length === 0) {
            return;
        }

        classifyFiles(batch).then(function (metas) {
            batch.forEach(function (file, i) {
                selectedFiles.push({
                    file: file,
                    meta: metas[i] || { ok: false, message: 'Could not classify', emoji: '📄', label: 'Unknown' }
                });
            });
            syncInputFromSelection();
            renderPreview();
        }).catch(function (err) {
            if (statusEl) {
                statusEl.classList.remove('hidden');
                statusEl.classList.add('is-error');
                statusEl.textContent = err.message || 'Could not detect file types.';
            }
        });
    }

    if (dropzone && fileInput) {
        browseBtn && browseBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            fileInput.click();
        });

        dropzone.addEventListener('click', function () {
            fileInput.click();
        });

        dropzone.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                fileInput.click();
            }
        });

        fileInput.addEventListener('change', function () {
            addFiles(fileInput.files);
            // Keep controlled list as source of truth.
            syncInputFromSelection();
        });

        ['dragenter', 'dragover'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'drop'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (evt === 'dragleave' && e.target !== dropzone) {
                    return;
                }
                dropzone.classList.remove('is-dragover');
            });
        });

        dropzone.addEventListener('drop', function (e) {
            var files = e.dataTransfer && e.dataTransfer.files;
            addFiles(files);
        });

        // Prevent browser opening dropped files outside the zone.
        ['dragover', 'drop'].forEach(function (evt) {
            window.addEventListener(evt, function (e) {
                if (e.target === dropzone || dropzone.contains(e.target)) {
                    return;
                }
                e.preventDefault();
            });
        });
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            var okCount = selectedFiles.filter(function (entry) { return entry.meta && entry.meta.ok; }).length;
            if (okCount < 1) {
                e.preventDefault();
                if (statusEl) {
                    statusEl.classList.remove('hidden');
                    statusEl.classList.add('is-error');
                    statusEl.textContent = 'Add at least one recognized Demand, Story, Task, or DDR file.';
                }
            }
        });
    }

    // ----- Dossier helpers -----
    var SECTION_DUP_STORAGE_KEY = 'ticket_dossier_hide_section_dups';
    var SOURCE_SECTION_ORDER = {
        'section-demand': 1,
        'section-story': 2,
        'section-task': 3,
        'section-ddr': 4
    };
    var toggleBtn = document.getElementById('toggle-all-fields');
    var sectionDupsBtn = document.getElementById('toggle-section-dups');
    var hideSectionDups = readSectionDupsPref();

    function readSectionDupsPref() {
        try {
            return window.localStorage.getItem(SECTION_DUP_STORAGE_KEY) === '1';
        } catch (err) {
            return false;
        }
    }

    function writeSectionDupsPref(enabled) {
        try {
            window.localStorage.setItem(SECTION_DUP_STORAGE_KEY, enabled ? '1' : '0');
        } catch (err) {
            // Ignore storage failures.
        }
    }

    function normalizeDupText(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .replace(/[•·]/g, '')
            .trim();
    }

    function clearSectionDupMarks() {
        document.querySelectorAll('.field-item[data-cross-dup]').forEach(function (el) {
            el.removeAttribute('data-cross-dup');
        });
        document.querySelectorAll('.panel[data-section-dup-count]').forEach(function (panel) {
            panel.removeAttribute('data-section-dup-count');
            var note = panel.querySelector('.section-dup-note');
            if (note) note.remove();
        });
        if (document.body) {
            document.body.classList.remove('dossier-hide-section-dups');
        }
    }

    function markCrossSectionFieldDups() {
        clearSectionDupMarks();
        if (!hideSectionDups) {
            updateSectionDupsButton();
            return 0;
        }

        var groups = {};
        document.querySelectorAll('#section-demand, #section-story, #section-task, #section-ddr').forEach(function (section) {
            var sectionId = section.id || '';
            var order = SOURCE_SECTION_ORDER[sectionId];
            if (!order) return;
            section.querySelectorAll('.field-item').forEach(function (el) {
                var dt = el.querySelector('dt');
                var dd = el.querySelector('dd');
                var label = normalizeDupText(dt ? dt.textContent : '');
                var value = normalizeDupText(dd ? dd.textContent : el.textContent);
                if (!label || !value || value === '-' || value === '—' || value === 'n/a') return;
                var key = label + '\0' + value;
                if (!groups[key]) groups[key] = [];
                groups[key].push({
                    el: el,
                    section: section,
                    sectionId: sectionId,
                    order: order
                });
            });
        });

        var hiddenCount = 0;
        var perSection = {};
        Object.keys(groups).forEach(function (key) {
            var entries = groups[key];
            if (entries.length < 2) return;
            // Keep one per section first, then hide later sections with the same label+value.
            var bySection = {};
            entries.forEach(function (entry) {
                if (!bySection[entry.sectionId] || entry.order < bySection[entry.sectionId].order) {
                    bySection[entry.sectionId] = entry;
                }
            });
            var uniqueSections = Object.keys(bySection).map(function (id) {
                return bySection[id];
            }).sort(function (a, b) {
                return a.order - b.order;
            });
            if (uniqueSections.length < 2) return;
            uniqueSections.slice(1).forEach(function (entry) {
                // Mark every matching field-item in that later section (summary + all-fields copies).
                entries.forEach(function (item) {
                    if (item.sectionId !== entry.sectionId) return;
                    item.el.setAttribute('data-cross-dup', '1');
                });
                perSection[entry.sectionId] = (perSection[entry.sectionId] || 0) + 1;
                hiddenCount += 1;
            });
        });

        Object.keys(perSection).forEach(function (sectionId) {
            var panel = document.getElementById(sectionId);
            if (!panel) return;
            var count = perSection[sectionId];
            panel.setAttribute('data-section-dup-count', String(count));
            var head = panel.querySelector('.section-head') || panel.querySelector('h2');
            if (!head) return;
            var note = document.createElement('span');
            note.className = 'section-dup-note';
            note.textContent = count + ' repeated field' + (count === 1 ? '' : 's') + ' hidden';
            if (head.classList.contains('section-head')) {
                head.appendChild(note);
            } else {
                head.insertAdjacentElement('afterend', note);
            }
        });

        if (document.body) {
            document.body.classList.add('dossier-hide-section-dups');
        }
        updateSectionDupsButton(hiddenCount);
        return hiddenCount;
    }

    function updateSectionDupsButton(hiddenCount) {
        if (!sectionDupsBtn) return;
        sectionDupsBtn.setAttribute('data-hide-dups', hideSectionDups ? '1' : '0');
        sectionDupsBtn.setAttribute('aria-pressed', hideSectionDups ? 'true' : 'false');
        if (hideSectionDups) {
            sectionDupsBtn.textContent = hiddenCount > 0
                ? '🧹 Show section dups (' + hiddenCount + ')'
                : '🧹 Show section dups';
            sectionDupsBtn.title = 'Show fields that were hidden because they repeat across Demand, Story, Task, and DDR';
            sectionDupsBtn.classList.add('is-active');
        } else {
            sectionDupsBtn.textContent = '🧹 Hide section dups';
            sectionDupsBtn.title = 'Hide fields that repeat with the same value across Demand, Story, Task, and DDR';
            sectionDupsBtn.classList.remove('is-active');
        }
    }

    function applySectionDupPreference() {
        markCrossSectionFieldDups();
        if (typeof initSearchIndex === 'function' && document.getElementById('global-search-input')) {
            initSearchIndex();
            if (lastSearchQuery && lastSearchQuery.length >= 2 && typeof performSearch === 'function') {
                performSearch(lastSearchQuery);
            }
        }
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            var showAll = toggleBtn.getAttribute('data-show-all') === '1';
            var next = !showAll;
            toggleBtn.setAttribute('data-show-all', next ? '1' : '0');
            toggleBtn.textContent = next ? '🙈 Hide empty fields' : '👁️ Show all fields';

            document.querySelectorAll('[data-fields]').forEach(function (el) {
                el.classList.toggle('hidden', next);
            });
            document.querySelectorAll('[data-fields-all]').forEach(function (el) {
                el.classList.toggle('hidden', !next);
            });
            applySectionDupPreference();
        });
    }

    if (sectionDupsBtn) {
        updateSectionDupsButton();
        sectionDupsBtn.addEventListener('click', function () {
            hideSectionDups = !hideSectionDups;
            writeSectionDupsPref(hideSectionDups);
            applySectionDupPreference();
        });
        // Apply saved preference once the page fields are present.
        markCrossSectionFieldDups();
    }

    var search = document.getElementById('qa-search');
    var answeredOnly = document.getElementById('qa-answered-only');

    function filterQa() {
        var q = (search && search.value ? search.value : '').trim().toLowerCase();
        var onlyAnswered = !!(answeredOnly && answeredOnly.checked);

        document.querySelectorAll('.qa-item').forEach(function (item) {
            var hay = item.getAttribute('data-q') || '';
            var isAnswered = item.classList.contains('answered');
            var matchesQuery = !q || hay.indexOf(q) !== -1;
            var matchesAnswered = !onlyAnswered || isAnswered;
            item.classList.toggle('hidden', !(matchesQuery && matchesAnswered));
        });
    }

    if (search) {
        search.addEventListener('input', filterQa);
    }
    if (answeredOnly) {
        answeredOnly.addEventListener('change', filterQa);
        filterQa();
    }

    var nav = document.getElementById('section-nav');
    if (nav && 'IntersectionObserver' in window) {
        var sentinel = document.createElement('div');
        sentinel.setAttribute('aria-hidden', 'true');
        sentinel.style.height = '1px';
        sentinel.style.marginTop = '-1px';
        nav.parentNode.insertBefore(sentinel, nav);
        var observer = new IntersectionObserver(function (entries) {
            nav.classList.toggle('is-stuck', !entries[0].isIntersecting);
        });
        observer.observe(sentinel);
    }

    // ----- Global search -----
    var globalSearchInput = document.getElementById('global-search-input');
    var searchClearBtn = document.getElementById('search-clear');
    var searchFuzzyToggle = document.getElementById('search-fuzzy-toggle');
    var searchResultsEl = document.getElementById('search-results');
    var searchJumpsEl = document.getElementById('search-jumps');
    var globalSearchContainer = document.getElementById('global-search');
    var searchableElements = [];
    var currentHighlights = [];
    var lastMatches = [];
    var lastParsedQuery = null;
    var lastSearchQuery = '';
    var activeMatchIndex = -1;
    var HIDE_DUPS_KEY = 'ticket_dossier_search_hide_dups';
    var FUZZY_KEY = 'ticket_dossier_search_fuzzy';
    var hideDuplicateMatches = readHideDupsPref();
    var fuzzyEnabled = readFuzzyPref();
    var pulseTimer = 0;
    var KIND_RANK = { exact: 0, contains: 1, phonetic: 2, fuzzy: 3 };
    var SECTION_PREF = {
        'section-overview': 0,
        hero: 1,
        'section-vendor': 2,
        'section-demand': 3,
        'section-ddr': 4,
        'section-story': 5,
        'section-task': 6,
        'section-assessments': 7,
        'section-files': 8
    };
    var ROLE_SHORTCUTS = [
        { id: 'vendor', label: 'Vendor', emoji: '🏢', aliases: ['vendor', 'third party vendor'] },
        { id: 'business-owner', label: 'Biz Owner', emoji: '👔', aliases: ['business owner'] },
        { id: 'sponsor', label: 'Sponsor', emoji: '⭐', aliases: ['ait executive sponsor', 'executive sponsor'] },
        { id: 'product-owner', label: 'Prod Owner', emoji: '🧩', aliases: ['ait product owner', 'product owner'] },
        { id: 'product-manager', label: 'Prod Mgr', emoji: '🧭', aliases: ['ait product manager', 'product manager'] },
        { id: 'demand-manager', label: 'Demand Mgr', emoji: '📋', aliases: ['ait demand manager', 'demand manager'] },
        { id: 'requested-by', label: 'Requester', emoji: '🙋', aliases: ['requested by', 'requester'] },
        { id: 'assignee', label: 'Assignee', emoji: '✅', aliases: ['assignee', 'assigned to'] },
        { id: 'owner', label: 'Owner', emoji: '👤', aliases: ['owner'] }
    ];
    var TOPIC_SHORTCUTS = [
        { id: 'assessments', label: 'Assessments', emoji: '📝', query: 'assessment', sectionId: 'section-assessments' },
        { id: 'business-case', label: 'Biz case', emoji: '💡', aliases: ['business case'], query: 'business case' },
        { id: 'funding-cfo', label: 'Funding CFO', emoji: '💰', aliases: ['funding cfo'] },
        { id: 'funding-status', label: 'Funding', emoji: '💵', aliases: ['funding status', 'funding'] },
        { id: 'priority', label: 'Priority', emoji: '⚡', aliases: ['priority', 'priority alignment'] },
        { id: 'portfolio', label: 'Portfolio', emoji: '📁', aliases: ['portfolio'] },
        { id: 'classification', label: 'Class', emoji: '🏷️', aliases: ['classification', 'extract classification'] },
        { id: 'state', label: 'State', emoji: '📌', aliases: ['state'] },
        { id: 'go-live', label: 'Go-Live', emoji: '🚀', aliases: ['qp-go-live', 'qp go live', 'go live', 'planned go live'] },
        { id: 'tprm', label: 'TPRM', emoji: '🛡️', query: 'TPRM', aliases: ['tprm recommendation'] },
        { id: 'risk', label: 'Risk', emoji: '⚠️', query: 'risk' },
        { id: 'description', label: 'Desc', emoji: '📄', aliases: ['description'] },
        { id: 'related', label: 'Related', emoji: '🔗', query: 'related', sectionId: 'section-demand' },
        { id: 'ddr', label: 'DDR', emoji: '🛡️', query: 'DDR', sectionId: 'section-ddr' },
        { id: 'exceptions', label: 'Exceptions', emoji: '⛔', query: 'exception', aliases: ['exception', 'exceptions', 'exception status'] }
    ];
    var EMPTYISH = ['', '—', '-', 'n/a', 'na', 'none', 'null', 'unknown', 'unknown owner', 'no answer', 'false'];
    var PRESET_STORAGE_KEY = 'ticket_dossier_search_presets_v1';
    var MAX_CUSTOM_PRESETS = 24;
    var searchJumpsListEl = document.getElementById('search-jumps-list');
    var presetManageBtn = document.getElementById('search-preset-manage');
    var presetForm = document.getElementById('search-preset-form');
    var presetNameInput = document.getElementById('preset-name');
    var presetEmojiInput = document.getElementById('preset-emoji');
    var presetFieldInput = document.getElementById('preset-field');
    var presetQueryInput = document.getElementById('preset-query');
    var presetExcludeInput = document.getElementById('preset-exclude');
    var presetCancelBtn = document.getElementById('preset-cancel');
    var presetErrorEl = document.getElementById('preset-error');
    var presetFieldSuggestions = document.getElementById('preset-field-suggestions');
    var customPresets = loadCustomPresets();
    var searchTimer = 0;
    var presetCountCache = {};

    function fuzzyApi() {
        return window.FuzzySearch || null;
    }

    function normalizeLabel(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/&/g, 'and')
            .replace(/[^a-z0-9]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function collapseText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function isEmptyish(value) {
        var text = collapseText(value).toLowerCase();
        return EMPTYISH.indexOf(text) !== -1;
    }

    function headingText(root) {
        if (!root) return '';
        var heading = root.querySelector('h2, h3, h4');
        return heading ? collapseText(heading.textContent) : '';
    }

    function sectionInfo(el) {
        var section = el.closest('.panel, .hero, .ribbon');
        var id = '';
        if (section) {
            id = section.id || (section.classList.contains('hero') ? 'hero' : (section.classList.contains('ribbon') ? 'ribbon' : ''));
        }
        return {
            node: section,
            id: id,
            title: headingText(section)
        };
    }

    function shouldSkipIndex(el) {
        if (!el) return true;
        if (el.closest('#global-search, .upload-card, .section-nav, .topbar, .details-form, .assess-toolbar')) {
            return true;
        }
        if (hideSectionDups && el.closest('.field-item[data-cross-dup="1"]')) {
            return true;
        }
        return false;
    }

    function seenKey(sectionId, label, text) {
        return (sectionId || '') + '\0' + normalizeLabel(label) + '\0' + collapseText(text).slice(0, 80).toLowerCase();
    }

    function pushSearchItem(bag, item) {
        if (!item || !item.element) return;
        var text = collapseText(item.originalText);
        var label = collapseText(item.label);
        if (!text && !label) return;
        if (isEmptyish(text) && isEmptyish(label)) return;
        var key = seenKey(item.sectionId, label, text);
        if (bag.seen[key]) return;
        bag.seen[key] = true;
        item.originalText = text;
        item.label = label || 'Content';
        item.text = (label + ' ' + text).toLowerCase();
        bag.items.push(item);
    }

    function indexFieldItem(bag, el, preferVisible) {
        if (!el || shouldSkipIndex(el)) return;
        if (hideSectionDups && el.getAttribute('data-cross-dup') === '1') return;
        var dt = el.querySelector('dt');
        var dd = el.querySelector('dd');
        var label = dt ? collapseText(dt.textContent) : '';
        var value = dd ? collapseText(dd.textContent) : collapseText(el.textContent);
        if (preferVisible && isEmptyish(value)) return;
        var info = sectionInfo(el);
        pushSearchItem(bag, {
            element: dd || el,
            pulseTarget: el,
            originalText: value,
            label: label || 'Field',
            section: info.node,
            sectionId: info.id,
            sectionTitle: info.title,
            hidden: !!(el.closest('[data-fields-all]') && el.closest('[data-fields-all]').classList.contains('hidden'))
        });
    }

    function initSearchIndex() {
        var bag = { items: [], seen: {} };

        document.querySelectorAll('.fields-wrap[data-fields] .field-item').forEach(function (el) {
            indexFieldItem(bag, el, true);
        });
        document.querySelectorAll('.fields-wrap[data-fields-all] .field-item').forEach(function (el) {
            indexFieldItem(bag, el, false);
        });

        document.querySelectorAll('.kv > div').forEach(function (el) {
            if (shouldSkipIndex(el)) return;
            var dt = el.querySelector('dt');
            var dd = el.querySelector('dd');
            var info = sectionInfo(el);
            pushSearchItem(bag, {
                element: dd || el,
                pulseTarget: el,
                originalText: dd ? collapseText(dd.textContent) : collapseText(el.textContent),
                label: dt ? collapseText(dt.textContent) : 'Key fact',
                section: info.node,
                sectionId: info.id,
                sectionTitle: info.title || 'Overview'
            });
        });

        document.querySelectorAll('.prose-block').forEach(function (el) {
            if (shouldSkipIndex(el)) return;
            var heading = el.querySelector('h3');
            var body = el.querySelector('p') || el;
            var info = sectionInfo(el);
            pushSearchItem(bag, {
                element: body,
                pulseTarget: el,
                originalText: collapseText(body.textContent),
                label: heading ? collapseText(heading.textContent) : 'Description',
                section: info.node,
                sectionId: info.id,
                sectionTitle: info.title
            });
        });

        document.querySelectorAll('.overview-card > p').forEach(function (el) {
            if (shouldSkipIndex(el) || el.closest('.prose-block, .meta-card')) return;
            var card = el.closest('.overview-card');
            var heading = card ? card.querySelector('h3') : null;
            var info = sectionInfo(el);
            pushSearchItem(bag, {
                element: el,
                pulseTarget: card || el,
                originalText: collapseText(el.textContent),
                label: heading ? collapseText(heading.textContent) : 'Overview',
                section: info.node,
                sectionId: info.id,
                sectionTitle: info.title || 'Overview'
            });
        });

        document.querySelectorAll('.qa-item').forEach(function (el) {
            if (shouldSkipIndex(el)) return;
            var q = el.querySelector('.q');
            var a = el.querySelector('.a');
            var question = q ? collapseText(q.textContent) : '';
            var answer = a ? collapseText(a.textContent) : '';
            var info = sectionInfo(el);
            var block = el.closest('.qa-block');
            pushSearchItem(bag, {
                element: q || el,
                pulseTarget: el,
                originalText: collapseText(question + (answer ? ' ' + answer : '')),
                label: question ? question.slice(0, 80) : 'Question',
                section: info.node,
                sectionId: info.id,
                sectionTitle: headingText(block) || info.title,
                hidden: el.classList.contains('hidden')
            });
        });

        document.querySelectorAll('.ribbon-node').forEach(function (el) {
            var labelEl = el.querySelector('.ribbon-label');
            var info = sectionInfo(el);
            pushSearchItem(bag, {
                element: el,
                pulseTarget: el,
                originalText: collapseText(el.textContent),
                label: labelEl ? collapseText(labelEl.textContent) : 'Record',
                section: info.node,
                sectionId: info.id || 'ribbon',
                sectionTitle: 'Record relationship'
            });
        });

        document.querySelectorAll('.hero [data-search-label], .kv [data-search-label]').forEach(function (el) {
            if (shouldSkipIndex(el)) return;
            var info = sectionInfo(el);
            var label = collapseText(el.getAttribute('data-search-label') || '');
            pushSearchItem(bag, {
                element: el,
                pulseTarget: el.closest('.pill, .kv > div') || el,
                originalText: collapseText(el.textContent),
                label: label || getLabelForElement(el),
                section: info.node,
                sectionId: info.id,
                sectionTitle: info.title || 'Overview'
            });
        });

        document.querySelectorAll('.related-list li').forEach(function (el) {
            if (shouldSkipIndex(el)) return;
            var info = sectionInfo(el);
            pushSearchItem(bag, {
                element: el,
                pulseTarget: el,
                originalText: collapseText(el.textContent),
                label: 'Related record',
                section: info.node,
                sectionId: info.id,
                sectionTitle: info.title
            });
        });

        document.querySelectorAll('.file-list a').forEach(function (el) {
            if (shouldSkipIndex(el)) return;
            var info = sectionInfo(el);
            pushSearchItem(bag, {
                element: el,
                pulseTarget: el.closest('li') || el,
                originalText: collapseText(el.textContent),
                label: 'File',
                section: info.node,
                sectionId: info.id,
                sectionTitle: info.title || 'Files'
            });
        });

        document.querySelectorAll('.hero h2').forEach(function (el) {
            var info = sectionInfo(el);
            pushSearchItem(bag, {
                element: el,
                pulseTarget: el,
                originalText: collapseText(el.textContent),
                label: 'Project name',
                section: info.node,
                sectionId: info.id || 'hero',
                sectionTitle: 'Project dossier'
            });
        });

        searchableElements = bag.items;
        invalidatePresetCountCache();
        renderRoleShortcuts();
    }

    function getLabelForElement(el) {
        if (el.getAttribute && el.getAttribute('data-search-label')) {
            return collapseText(el.getAttribute('data-search-label'));
        }
        var fieldItem = el.closest('.field-item');
        if (fieldItem) {
            var dt = fieldItem.querySelector('dt');
            if (dt) return collapseText(dt.textContent);
        }
        var kvItem = el.closest('.kv > div');
        if (kvItem) {
            var kvDt = kvItem.querySelector('dt');
            if (kvDt) return collapseText(kvDt.textContent);
        }
        if (el.classList.contains('vendor')) return 'Vendor';
        if (el.classList.contains('q')) return 'Question';
        if (el.classList.contains('a')) return 'Answer';
        if (el.closest('.ribbon-node')) {
            var ribbonNode = el.closest('.ribbon-node');
            var label = ribbonNode.querySelector('.ribbon-label');
            if (label) return collapseText(label.textContent);
        }
        return 'Content';
    }

    function sectionRank(sectionId) {
        if (Object.prototype.hasOwnProperty.call(SECTION_PREF, sectionId)) {
            return SECTION_PREF[sectionId];
        }
        return 20;
    }

    function isPlaceholderValue(value) {
        var text = collapseText(value)
            .replace(/^[^\w(]+/, '')
            .replace(/^(owner|vendor)\s*:\s*/i, '')
            .trim();
        return isEmptyish(text);
    }

    function loadCustomPresets() {
        try {
            var raw = window.localStorage.getItem(PRESET_STORAGE_KEY);
            if (!raw) return [];
            var parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) return [];
            return parsed
                .map(normalizePreset)
                .filter(Boolean)
                .slice(0, MAX_CUSTOM_PRESETS);
        } catch (err) {
            return [];
        }
    }

    function normalizePreset(preset) {
        if (!preset || typeof preset !== 'object') return null;
        var label = collapseText(preset.label || preset.name || '');
        var field = collapseText(preset.field || preset.fieldLabel || '');
        var query = collapseText(preset.query || '');
        var exclude = collapseText(preset.exclude || preset.exception || preset.exceptions || '');
        var emoji = collapseText(preset.emoji || '🔖').slice(0, 8);
        if (label === '' || (field === '' && query === '')) return null;
        var id = collapseText(preset.id || '');
        if (id === '') {
            id = 'preset-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 7);
        }
        return {
            id: id,
            label: label.slice(0, 40),
            emoji: emoji || '🔖',
            field: field.slice(0, 120),
            query: query.slice(0, 200),
            exclude: exclude.slice(0, 200)
        };
    }

    function buildExcludeTerms(excludeText) {
        var raw = collapseText(excludeText);
        if (!raw) return [];
        var terms = [];
        raw.split(/[,\s]+/).forEach(function (part) {
            var term = collapseText(part).replace(/^-+/, '');
            if (term.length < 2) return;
            if (terms.indexOf(term) === -1) terms.push(term);
        });
        return terms;
    }

    function buildPresetSearchQuery(preset) {
        var parts = [];
        if (preset.query) parts.push(preset.query);
        buildExcludeTerms(preset.exclude).forEach(function (term) {
            parts.push('-' + term);
        });
        return parts.join(' ').trim();
    }

    function countAliasMatches(aliases) {
        if (!aliases || !aliases.length) return 0;
        var normalized = aliases.map(normalizeLabel).filter(Boolean);
        if (!normalized.length) return 0;
        var count = 0;
        searchableElements.forEach(function (item) {
            var lab = normalizeLabel(item.label);
            if (normalized.indexOf(lab) === -1) return;
            if (isPlaceholderValue(item.originalText)) return;
            count += 1;
        });
        return count;
    }

    function countFieldLabelMatches(fieldLabel) {
        var needle = normalizeLabel(fieldLabel);
        if (!needle) return 0;
        var count = 0;
        searchableElements.forEach(function (item) {
            var lab = normalizeLabel(item.label);
            if (!lab) return;
            if (lab === needle || lab.indexOf(needle) !== -1 || (needle.indexOf(lab) !== -1 && lab.length >= 4)) {
                count += 1;
            }
        });
        return count;
    }

    function countQueryMatches(query) {
        var q = collapseText(query);
        if (!q || q.length < 2) return 0;
        var cacheKey = (fuzzyEnabled ? '1' : '0') + '\0' + q.toLowerCase();
        if (Object.prototype.hasOwnProperty.call(presetCountCache, cacheKey)) {
            return presetCountCache[cacheKey];
        }
        var parsed = parseSearchQuery(q);
        var count = 0;
        searchableElements.forEach(function (item) {
            var scored = scoreItem(item, parsed);
            if (scored && scored.matched) count += 1;
        });
        presetCountCache[cacheKey] = count;
        return count;
    }

    function countRoleMatches(role) {
        return countAliasMatches(role.aliases || []);
    }

    function countTopicMatches(topic) {
        var aliasCount = countAliasMatches(topic.aliases || []);
        if (aliasCount > 0) return aliasCount;
        if (topic.query) return countQueryMatches(topic.query);
        if (topic.sectionId && document.getElementById(topic.sectionId)) return 1;
        return 0;
    }

    function countCustomPresetMatches(preset) {
        var fieldCount = preset.field ? countFieldLabelMatches(preset.field) : 0;
        var searchQuery = buildPresetSearchQuery(preset);
        var queryCount = searchQuery ? countQueryMatches(searchQuery) : 0;
        if (preset.query || preset.exclude) {
            return queryCount > 0 ? queryCount : fieldCount;
        }
        return fieldCount;
    }

    function invalidatePresetCountCache() {
        presetCountCache = {};
    }

    function saveCustomPresets() {
        try {
            window.localStorage.setItem(PRESET_STORAGE_KEY, JSON.stringify(customPresets));
        } catch (err) {
            // Ignore quota / private-mode failures.
        }
    }

    function setPresetFormOpen(open) {
        if (!presetForm || !presetManageBtn) return;
        presetForm.classList.toggle('hidden', !open);
        presetManageBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        presetManageBtn.textContent = open ? 'Close' : '+ Custom preset';
        if (presetErrorEl) {
            presetErrorEl.classList.add('hidden');
            presetErrorEl.textContent = '';
        }
        if (open && presetNameInput) {
            refreshFieldSuggestions();
            if (!presetQueryInput.value && globalSearchInput && globalSearchInput.value.trim()) {
                presetQueryInput.value = globalSearchInput.value.trim();
            }
            presetNameInput.focus();
        }
    }

    function refreshFieldSuggestions() {
        if (!presetFieldSuggestions) return;
        var labels = {};
        searchableElements.forEach(function (item) {
            var label = collapseText(item.label);
            if (label && !isEmptyish(label)) labels[label] = true;
        });
        var options = Object.keys(labels).sort(function (a, b) {
            return a.localeCompare(b);
        }).slice(0, 120);
        presetFieldSuggestions.innerHTML = '';
        options.forEach(function (label) {
            var option = document.createElement('option');
            option.value = label;
            presetFieldSuggestions.appendChild(option);
        });
    }

    function findFieldByLabel(fieldLabel, requireValue) {
        var needle = normalizeLabel(fieldLabel);
        if (!needle) return null;
        var best = null;
        var bestScore = -1;
        searchableElements.forEach(function (item) {
            var lab = normalizeLabel(item.label);
            if (!lab) return;
            if (requireValue && isPlaceholderValue(item.originalText)) return;
            var score = -1;
            if (lab === needle) score = 300;
            else if (lab.indexOf(needle) === 0) score = 220;
            else if (lab.indexOf(needle) !== -1) score = 180;
            else if (needle.indexOf(lab) !== -1 && lab.length >= 4) score = 140;
            if (score < 0) return;
            score -= sectionRank(item.sectionId);
            if (!best || score > bestScore) {
                best = item;
                bestScore = score;
            }
        });
        return best;
    }

    function findRoleItem(role) {
        var aliases = role.aliases.map(normalizeLabel);
        var best = null;
        var bestRank = 99;
        searchableElements.forEach(function (item) {
            var lab = normalizeLabel(item.label);
            if (aliases.indexOf(lab) === -1) return;
            if (isPlaceholderValue(item.originalText)) return;
            var rank = sectionRank(item.sectionId);
            if (!best || rank < bestRank) {
                best = item;
                bestRank = rank;
            }
        });
        return best;
    }

    function floatingSearchApi() {
        return window.TicketDossierFloatingSearch || null;
    }

    function openFloatingSearch(options) {
        var api = floatingSearchApi();
        if (api && typeof api.open === 'function') {
            api.open(options || { focus: false, collapsed: false });
        }
    }

    function syncFloatingSearch(options) {
        var api = floatingSearchApi();
        if (!api || typeof api.sync !== 'function') return;
        var display = visibleSearchMatches();
        api.sync({
            query: lastSearchQuery,
            matchCount: display.length,
            activeIndex: activeMatchIndex,
            open: !!(options && options.open),
            focus: !!(options && options.focus)
        });
    }

    function runCustomPreset(preset) {
        openFloatingSearch({ focus: false, collapsed: false });
        var jumped = false;
        if (preset.field) {
            var item = findFieldByLabel(preset.field, false);
            if (item) {
                jumpToMatch(item);
                jumped = true;
            }
        }
        var searchQuery = buildPresetSearchQuery(preset);
        if (searchQuery && globalSearchInput) {
            globalSearchInput.value = searchQuery;
            performSearch(searchQuery, { openDock: true });
            if (searchClearBtn) searchClearBtn.classList.remove('hidden');
            if (!jumped && lastMatches.length > 0) {
                goToVisibleMatch(0, { openDock: true });
            } else if (!jumped) {
                globalSearchInput.focus();
            }
            return;
        }
        if (preset.field) {
            if (globalSearchInput) {
                globalSearchInput.value = preset.field;
                performSearch(preset.field, { openDock: true });
                if (searchClearBtn) searchClearBtn.classList.remove('hidden');
                if (!jumped && visibleSearchMatches().length > 0) {
                    goToVisibleMatch(0, { openDock: true });
                }
            }
        } else {
            syncFloatingSearch({ open: true });
        }
    }

    function deleteCustomPreset(presetId) {
        customPresets = customPresets.filter(function (preset) {
            return preset.id !== presetId;
        });
        saveCustomPresets();
        invalidatePresetCountCache();
        renderRoleShortcuts();
    }

    function appendJumpChip(list, options) {
        var wrap = document.createElement('div');
        wrap.className = 'search-jump-chip' + (options.custom ? ' is-custom' : '');
        wrap.setAttribute('role', 'listitem');

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'search-jump';
        var count = typeof options.count === 'number' ? options.count : null;
        var title = options.title || options.label;
        if (count !== null && count > 0) {
            title += ' — ' + count + ' on this dossier';
        }
        btn.title = title;

        if (options.emoji) {
            var emojiSpan = document.createElement('span');
            emojiSpan.className = 'search-jump-emoji';
            emojiSpan.setAttribute('aria-hidden', 'true');
            emojiSpan.textContent = options.emoji;
            btn.appendChild(emojiSpan);
            btn.appendChild(document.createTextNode(' '));
        }

        var labelSpan = document.createElement('span');
        labelSpan.className = 'search-jump-label';
        labelSpan.textContent = options.label;
        btn.appendChild(labelSpan);

        if (count !== null && count > 0) {
            var countSpan = document.createElement('span');
            countSpan.className = 'search-jump-count';
            countSpan.textContent = String(count);
            countSpan.setAttribute('aria-label', count + ' matches');
            btn.appendChild(countSpan);
            wrap.classList.add('has-count');
        } else if (count === 0) {
            wrap.classList.add('is-missing');
        }

        btn.addEventListener('mousedown', function (e) {
            e.stopPropagation();
        });
        btn.addEventListener('touchstart', function (e) {
            e.stopPropagation();
        }, { passive: true });
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof options.onClick === 'function') {
                options.onClick(e);
            }
        });
        wrap.appendChild(btn);

        if (options.custom && options.onDelete) {
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'search-jump-remove';
            remove.setAttribute('aria-label', 'Remove preset ' + options.label);
            remove.title = 'Remove preset';
            remove.textContent = '×';
            remove.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                options.onDelete();
            });
            wrap.appendChild(remove);
        }

        if (options.missing) {
            wrap.classList.add('is-missing');
            btn.title = (options.title || options.label) + ' — not found on this dossier';
        }

        list.appendChild(wrap);
    }

    function runTopicShortcut(topic) {
        openFloatingSearch({ focus: false, collapsed: false });
        var jumped = false;
        if (topic.aliases && topic.aliases.length) {
            var item = findRoleItem({ aliases: topic.aliases });
            if (!item && topic.aliases[0]) {
                item = findFieldByLabel(topic.aliases[0], false);
            }
            if (item) {
                jumpToMatch(item);
                jumped = true;
            }
        }
        var shortcutQuery = topic.query
            || (topic.aliases && topic.aliases[0])
            || topic.label;
        if (shortcutQuery && globalSearchInput) {
            globalSearchInput.value = shortcutQuery;
            performSearch(shortcutQuery, { openDock: true, focus: false });
            if (searchClearBtn) searchClearBtn.classList.remove('hidden');
            if (!jumped && visibleSearchMatches().length > 0) {
                goToVisibleMatch(0, { openDock: true });
                jumped = true;
            }
        }
        if (!jumped && topic.sectionId) {
            var section = document.getElementById(topic.sectionId);
            if (section) {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                clearJumpFocus();
                section.classList.add('search-jump-focus', 'search-jump-pulse');
                if (pulseTimer) window.clearTimeout(pulseTimer);
                pulseTimer = window.setTimeout(function () {
                    section.classList.remove('search-jump-pulse');
                }, 1800);
            }
        }
        syncFloatingSearch({ open: true });
    }

    function renderRoleShortcuts() {
        if (!searchJumpsEl) return;
        var list = searchJumpsListEl;
        if (!list) {
            list = document.createElement('div');
            list.className = 'search-jumps-list';
            list.id = 'search-jumps-list';
            list.setAttribute('role', 'list');
            searchJumpsEl.appendChild(list);
            searchJumpsListEl = list;
        }
        list.innerHTML = '';

        ROLE_SHORTCUTS.forEach(function (role) {
            var count = countRoleMatches(role);
            if (count < 1) return;
            var item = findRoleItem(role);
            if (!item) return;
            appendJumpChip(list, {
                label: role.label,
                emoji: role.emoji,
                count: count,
                title: 'Jump to ' + (role.aliases && role.aliases[0] ? role.aliases[0] : role.label),
                onClick: function () {
                    openFloatingSearch({ focus: false, collapsed: false });
                    var roleQuery = (role.aliases && role.aliases[0]) || role.label;
                    if (globalSearchInput) {
                        globalSearchInput.value = roleQuery;
                        performSearch(roleQuery, { openDock: true, focus: false });
                        if (searchClearBtn) searchClearBtn.classList.remove('hidden');
                    }
                    jumpToMatch(item);
                    syncFloatingSearch({ open: true });
                }
            });
        });

        TOPIC_SHORTCUTS.forEach(function (topic) {
            var count = countTopicMatches(topic);
            var present = count > 0;
            if (!present && topic.sectionId) {
                present = !!document.getElementById(topic.sectionId);
            }
            appendJumpChip(list, {
                label: topic.label,
                emoji: topic.emoji,
                count: count,
                missing: !present,
                title: topic.query ? ('Search “' + topic.query + '”') : ('Jump to ' + topic.label),
                onClick: function () {
                    runTopicShortcut(topic);
                }
            });
        });

        customPresets.forEach(function (preset) {
            var count = countCustomPresetMatches(preset);
            var present = count > 0 || (!preset.field && !!(preset.query || preset.exclude));
            if (preset.field && count < 1) {
                present = !!findFieldByLabel(preset.field, false);
            }
            var titleParts = [];
            if (preset.field) titleParts.push('Jump to “' + preset.field + '”');
            if (preset.query) titleParts.push('Search “' + preset.query + '”');
            if (preset.exclude) titleParts.push('Exclude “' + preset.exclude + '”');
            appendJumpChip(list, {
                label: preset.label,
                emoji: preset.emoji,
                custom: true,
                count: count,
                missing: !present,
                title: titleParts.join(' · ') || preset.label,
                onClick: function () {
                    runCustomPreset(preset);
                },
                onDelete: function () {
                    deleteCustomPreset(preset.id);
                }
            });
        });

        searchJumpsEl.classList.toggle('is-empty', list.children.length === 0);
    }

    function showPresetError(message) {
        if (!presetErrorEl) return;
        presetErrorEl.textContent = message;
        presetErrorEl.classList.toggle('hidden', !message);
    }

    function handlePresetSubmit(e) {
        e.preventDefault();
        var preset = normalizePreset({
            label: presetNameInput ? presetNameInput.value : '',
            emoji: presetEmojiInput ? presetEmojiInput.value : '',
            field: presetFieldInput ? presetFieldInput.value : '',
            query: presetQueryInput ? presetQueryInput.value : '',
            exclude: presetExcludeInput ? presetExcludeInput.value : ''
        });
        if (!preset) {
            showPresetError('Enter a chip name and either a field label or a search query.');
            return;
        }
        var duplicate = customPresets.some(function (existing) {
            return normalizeLabel(existing.label) === normalizeLabel(preset.label);
        });
        if (duplicate) {
            showPresetError('A preset with that name already exists.');
            return;
        }
        if (customPresets.length >= MAX_CUSTOM_PRESETS) {
            showPresetError('You can save up to ' + MAX_CUSTOM_PRESETS + ' custom presets.');
            return;
        }
        customPresets.push(preset);
        saveCustomPresets();
        invalidatePresetCountCache();
        renderRoleShortcuts();
        if (presetForm) presetForm.reset();
        setPresetFormOpen(false);
    }

    function parseSearchQuery(raw) {
        var query = String(raw || '').trim();
        var api = fuzzyApi();
        var words = [];
        var phrases = [];
        var excludes = [];
        if (api && typeof api.parseCatalogQuery === 'function') {
            var parsed = api.parseCatalogQuery(query);
            phrases = parsed.phrases || [];
            excludes = parsed.excludes || [];
            words = (parsed.words || []).slice();
            if (parsed.person) words.push(parsed.person);
            if (parsed.modifiedBy) words.push(parsed.modifiedBy);
            if (parsed.createdBy) words.push(parsed.createdBy);
            (parsed.tags || []).forEach(function (tag) { words.push(tag); });
            (parsed.paths || []).forEach(function (path) { words.push(path); });
        }
        if (words.length === 0 && phrases.length === 0) {
            words = api && typeof api.getSearchWords === 'function'
                ? api.getSearchWords(query)
                : query.toLowerCase().split(/\s+/).filter(Boolean);
        }
        return { query: query, words: words, phrases: phrases, excludes: excludes };
    }

    function scoreItem(item, parsed) {
        var hay = item.text || '';
        var valueHay = (item.originalText || '').toLowerCase();
        var labelHay = (item.label || '').toLowerCase();
        var i;
        for (i = 0; i < parsed.excludes.length; i++) {
            var excluded = String(parsed.excludes[i] || '').toLowerCase();
            if (excluded && hay.indexOf(excluded) !== -1) {
                return null;
            }
        }
        for (i = 0; i < parsed.phrases.length; i++) {
            var phrase = String(parsed.phrases[i] || '').toLowerCase();
            if (phrase && hay.indexOf(phrase) === -1) {
                return null;
            }
        }

        var api = fuzzyApi();
        var words = parsed.words.slice();
        if (parsed.phrases.length && words.length === 0) {
            parsed.phrases.forEach(function (phrase) {
                String(phrase).split(/\s+/).filter(Boolean).forEach(function (part) {
                    words.push(part);
                });
            });
        }
        if (words.length === 0) return null;

        if (api && typeof api.scoreLabeledFieldsAgainstWords === 'function') {
            var fields = [
                { text: item.originalText, sourceLabel: item.label, sourceName: item.sectionTitle },
                { text: item.label, sourceLabel: 'Field name', sourceName: item.sectionTitle }
            ];
            var scored = api.scoreLabeledFieldsAgainstWords(fields, words, 'and', fuzzyEnabled);
            if (scored && scored.matched) {
                if (labelHay && words.some(function (word) { return labelHay === String(word).toLowerCase(); })) {
                    scored.score = Math.min(100, scored.score + 4);
                }
                if (item.sectionId === 'section-overview' || item.sectionId === 'hero') {
                    scored.score = Math.min(100, scored.score + 2);
                }
                return scored;
            }
            return null;
        }

        var allFound = words.every(function (word) {
            return hay.indexOf(String(word).toLowerCase()) !== -1;
        });
        if (!allFound) return null;
        var exact = words.some(function (word) {
            return valueHay === String(word).toLowerCase() || labelHay === String(word).toLowerCase();
        });
        return { matched: true, score: exact ? 100 : 92, kind: exact ? 'exact' : 'contains' };
    }

    function excerptFor(item, parsed, match) {
        var api = fuzzyApi();
        var source = item.originalText || item.label;
        var needle = parsed.query;
        if (match && match.token) needle = match.token;
        else if (parsed.phrases[0]) needle = parsed.phrases[0];
        else if (parsed.words[0]) needle = parsed.words[0];
        if (api && typeof api.excerptAroundMatch === 'function') {
            return api.excerptAroundMatch(source, needle, 36) || source;
        }
        if (source.length <= 90) return source;
        var idx = source.toLowerCase().indexOf(String(needle).toLowerCase());
        if (idx < 0) return source.slice(0, 87) + '…';
        var start = Math.max(0, idx - 28);
        var end = Math.min(source.length, idx + String(needle).length + 28);
        return (start > 0 ? '…' : '') + source.slice(start, end) + (end < source.length ? '…' : '');
    }

    function markExcerpt(excerpt, parsed, match) {
        var needles = [];
        if (parsed.query) needles.push(parsed.query);
        if (match && match.token) needles.push(match.token);
        parsed.phrases.forEach(function (phrase) { needles.push(phrase); });
        parsed.words.forEach(function (word) { needles.push(word); });
        var hay = excerpt.toLowerCase();
        var chosen = '';
        var index = -1;
        needles.forEach(function (needle) {
            var n = String(needle || '').toLowerCase();
            if (n.length < 2) return;
            var idx = hay.indexOf(n);
            if (idx !== -1 && n.length > chosen.length) {
                chosen = n;
                index = idx;
            }
        });
        if (index === -1) return escapeHtml(excerpt);
        return escapeHtml(excerpt.slice(0, index))
            + '<span class="highlight-match">' + escapeHtml(excerpt.slice(index, index + chosen.length)) + '</span>'
            + escapeHtml(excerpt.slice(index + chosen.length));
    }

    function kindLabel(kind) {
        var api = fuzzyApi();
        if (api && typeof api.matchKindLabel === 'function') {
            return api.matchKindLabel(kind);
        }
        if (kind === 'exact') return 'Exact';
        if (kind === 'contains') return 'Contains';
        if (kind === 'phonetic') return 'Sounds like';
        if (kind === 'fuzzy') return 'Close spelling';
        return 'Match';
    }

    function clearJumpFocus() {
        document.querySelectorAll('.search-jump-pulse, .search-jump-focus').forEach(function (el) {
            el.classList.remove('search-jump-pulse', 'search-jump-focus');
        });
        if (pulseTimer) {
            window.clearTimeout(pulseTimer);
            pulseTimer = 0;
        }
    }

    function clearHighlights() {
        currentHighlights.forEach(function (el) {
            var parent = el.parentNode;
            if (!parent) return;
            parent.replaceChild(document.createTextNode(el.textContent), el);
            parent.normalize();
        });
        currentHighlights = [];
        clearJumpFocus();
    }

    function highlightNeedles(element, needles) {
        if (!element) return;
        var unique = [];
        needles.forEach(function (needle) {
            var n = String(needle || '').trim().toLowerCase();
            if (n.length < 2 || unique.indexOf(n) !== -1) return;
            unique.push(n);
        });
        unique.sort(function (a, b) { return b.length - a.length; });
        if (unique.length === 0) return;

        var walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT, null);
        var nodes = [];
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }
        nodes.forEach(function (node) {
            var text = node.nodeValue;
            if (!text) return;
            var lower = text.toLowerCase();
            var bestIndex = -1;
            var bestLen = 0;
            unique.forEach(function (needle) {
                var idx = lower.indexOf(needle);
                if (idx !== -1 && needle.length > bestLen) {
                    bestIndex = idx;
                    bestLen = needle.length;
                }
            });
            if (bestIndex === -1 || !node.parentNode) return;
            var mark = document.createElement('span');
            mark.className = 'highlight-match';
            mark.textContent = text.substring(bestIndex, bestIndex + bestLen);
            var frag = document.createDocumentFragment();
            if (bestIndex > 0) frag.appendChild(document.createTextNode(text.substring(0, bestIndex)));
            frag.appendChild(mark);
            if (bestIndex + bestLen < text.length) {
                frag.appendChild(document.createTextNode(text.substring(bestIndex + bestLen)));
            }
            node.parentNode.replaceChild(frag, node);
            currentHighlights.push(mark);
        });
    }

    function revealMatch(item) {
        if (!item || !item.element) return;
        var allWrap = item.element.closest('[data-fields-all]');
        if (allWrap && allWrap.classList.contains('hidden')) {
            if (toggleBtn && toggleBtn.getAttribute('data-show-all') !== '1') {
                toggleBtn.click();
            } else {
                allWrap.classList.remove('hidden');
                var visible = allWrap.parentNode ? allWrap.parentNode.querySelector('[data-fields]') : null;
                if (visible) visible.classList.add('hidden');
            }
        }
        var details = item.element.closest('details');
        if (details && !details.open) {
            details.open = true;
        }
        var qaItem = item.element.closest('.qa-item');
        if (qaItem) {
            qaItem.classList.remove('hidden');
        }
    }

    function jumpToMatch(item, options) {
        if (!item || !item.element) return;
        options = options || {};
        if (options.openDock) {
            openFloatingSearch({ focus: false, collapsed: false });
        }
        revealMatch(item);
        var display = visibleSearchMatches();
        var foundIdx = -1;
        display.forEach(function (match, idx) {
            if (match.item === item || (match.item && match.item.element === item.element)) {
                foundIdx = idx;
            }
        });
        if (foundIdx >= 0) {
            activeMatchIndex = foundIdx;
        }
        window.requestAnimationFrame(function () {
            var target = item.pulseTarget || item.element;
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            clearJumpFocus();
            // Lasting focus color so the match stays easy to spot; brief pulse draws attention.
            target.classList.add('search-jump-focus', 'search-jump-pulse');
            if (pulseTimer) window.clearTimeout(pulseTimer);
            pulseTimer = window.setTimeout(function () {
                target.classList.remove('search-jump-pulse');
                // Keep search-jump-focus until the next jump or clear.
            }, 1800);
            markActiveResultButton();
            syncFloatingSearch(options.openDock ? { open: true } : null);
        });
    }

    function goToVisibleMatch(index, options) {
        var display = visibleSearchMatches();
        if (display.length === 0) {
            activeMatchIndex = -1;
            syncFloatingSearch();
            return;
        }
        var next = index % display.length;
        if (next < 0) next = display.length + next;
        activeMatchIndex = next;
        jumpToMatch(display[next].item, options);
    }

    function goToNextMatch() {
        var display = visibleSearchMatches();
        if (display.length === 0) return;
        var next = activeMatchIndex < 0 ? 0 : activeMatchIndex + 1;
        if (next >= display.length) next = 0;
        goToVisibleMatch(next);
    }

    function goToPrevMatch() {
        var display = visibleSearchMatches();
        if (display.length === 0) return;
        var next = activeMatchIndex < 0 ? display.length - 1 : activeMatchIndex - 1;
        if (next < 0) next = display.length - 1;
        goToVisibleMatch(next);
    }

    function markActiveResultButton() {
        if (!searchResultsEl) return;
        var activeBtn = null;
        searchResultsEl.querySelectorAll('.search-match-item').forEach(function (button) {
            var idx = parseInt(button.getAttribute('data-match-idx'), 10);
            var isActive = idx === activeMatchIndex;
            button.classList.toggle('is-active', isActive);
            if (isActive) activeBtn = button;
        });
        if (activeBtn && typeof activeBtn.scrollIntoView === 'function') {
            activeBtn.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    function readHideDupsPref() {
        try {
            return window.localStorage.getItem(HIDE_DUPS_KEY) === '1';
        } catch (err) {
            return false;
        }
    }

    function writeHideDupsPref(enabled) {
        try {
            window.localStorage.setItem(HIDE_DUPS_KEY, enabled ? '1' : '0');
        } catch (err) {
            // Ignore storage failures.
        }
    }

    function readFuzzyPref() {
        try {
            var raw = window.localStorage.getItem(FUZZY_KEY);
            if (raw === null) return true;
            return raw === '1';
        } catch (err) {
            return true;
        }
    }

    function writeFuzzyPref(enabled) {
        try {
            window.localStorage.setItem(FUZZY_KEY, enabled ? '1' : '0');
        } catch (err) {
            // Ignore storage failures.
        }
    }

    function updateFuzzyToggle() {
        if (!searchFuzzyToggle) return;
        searchFuzzyToggle.classList.toggle('is-active', fuzzyEnabled);
        searchFuzzyToggle.setAttribute('aria-pressed', fuzzyEnabled ? 'true' : 'false');
        searchFuzzyToggle.title = fuzzyEnabled
            ? 'Fuzzy on — includes close spellings and sounds-like matches. Click to use exact/contains only.'
            : 'Fuzzy off — exact and contains matches only. Click to include close spellings.';
        searchFuzzyToggle.textContent = 'Fuzzy';
    }

    function matchDedupeKey(match) {
        var item = match && match.item ? match.item : {};
        return normalizeLabel(item.label || '') + '\0' + collapseText(item.originalText || '').toLowerCase();
    }

    function uniqueMatches(matches) {
        var seen = {};
        var out = [];
        matches.forEach(function (match) {
            var key = matchDedupeKey(match);
            if (seen[key]) return;
            seen[key] = true;
            out.push(match);
        });
        return out;
    }

    function visibleSearchMatches() {
        if (!hideDuplicateMatches) return lastMatches;
        return uniqueMatches(lastMatches);
    }

    function renderSearchResults() {
        if (!searchResultsEl) return;
        var matches = lastMatches;
        if (!lastSearchQuery || lastSearchQuery.length < 2) return;

        if (matches.length === 0) {
            activeMatchIndex = -1;
            searchResultsEl.classList.remove('hidden');
            searchResultsEl.classList.add('no-results');
            searchResultsEl.innerHTML = '<p>No matches found for “' + escapeHtml(lastSearchQuery) + '”</p>';
            syncFloatingSearch();
            return;
        }

        var display = visibleSearchMatches();
        var hiddenCount = Math.max(0, matches.length - display.length);
        if (activeMatchIndex >= display.length) {
            activeMatchIndex = display.length > 0 ? 0 : -1;
        }
        var shown = display;
        var parsed = lastParsedQuery || parseSearchQuery(lastSearchQuery);

        var head = '<div class="search-results-head">';
        head += '<span><strong>' + display.length + '</strong> match' + (display.length === 1 ? '' : 'es');
        if (hideDuplicateMatches && hiddenCount > 0) {
            head += ' <em class="search-dup-note">(' + hiddenCount + ' duplicate' + (hiddenCount === 1 ? '' : 's') + ' hidden)</em>';
        }
        head += ' — click to jump</span>';
        head += '<button type="button" class="search-hide-dups' + (hideDuplicateMatches ? ' is-active' : '') + '" id="search-hide-dups"';
        head += ' aria-pressed="' + (hideDuplicateMatches ? 'true' : 'false') + '"';
        head += ' title="' + (hideDuplicateMatches ? 'Show duplicate matches' : 'Hide duplicate matches') + '">';
        head += hideDuplicateMatches ? 'Show dups' : 'Hide dups';
        head += '</button></div>';

        var list = '<ul class="search-match-list">';
        shown.forEach(function (match, idx) {
            var item = match.item;
            var kindClass = 'is-' + (match.kind || 'contains');
            var activeClass = idx === activeMatchIndex ? ' is-active' : '';
            list += '<li>';
            list += '<button type="button" class="search-match-item' + activeClass + '" data-match-idx="' + idx + '">';
            list += '<div class="search-match-label">' + escapeHtml(item.label) + ' · ' + escapeHtml(item.sectionTitle || 'Dossier') + '</div>';
            list += '<div class="search-match-context">' + markExcerpt(match.snippet, parsed, match) + '</div>';
            list += '<div class="search-match-meta">';
            list += '<span class="search-match-kind ' + kindClass + '">' + escapeHtml(kindLabel(match.kind)) + '</span>';
            list += '<span class="search-match-score">' + Math.round(match.score) + '%</span>';
            list += '</div></button></li>';
        });
        list += '</ul>';

        searchResultsEl.classList.remove('hidden', 'no-results');
        searchResultsEl.innerHTML = head + list;

        var hideBtn = document.getElementById('search-hide-dups');
        if (hideBtn) {
            hideBtn.addEventListener('click', function () {
                hideDuplicateMatches = !hideDuplicateMatches;
                writeHideDupsPref(hideDuplicateMatches);
                if (activeMatchIndex > 0) activeMatchIndex = 0;
                renderSearchResults();
                syncFloatingSearch();
            });
        }

        searchResultsEl.querySelectorAll('.search-match-item').forEach(function (button) {
            button.addEventListener('click', function () {
                var idx = parseInt(button.getAttribute('data-match-idx'), 10);
                if (!isNaN(idx)) {
                    // Convert inline dropdown into the vertical floating panel on result click.
                    goToVisibleMatch(idx, { openDock: true });
                }
            });
        });

        syncFloatingSearch();
    }

    function performSearch(query, options) {
        options = options || {};
        clearHighlights();
        lastMatches = [];
        lastParsedQuery = null;
        lastSearchQuery = String(query || '').trim();
        activeMatchIndex = -1;

        if (!lastSearchQuery || lastSearchQuery.length < 2) {
            searchResultsEl.classList.add('hidden');
            searchResultsEl.classList.remove('no-results');
            searchResultsEl.innerHTML = '';
            if (searchClearBtn) searchClearBtn.classList.add('hidden');
            syncFloatingSearch(options.openDock ? { open: true, focus: !!options.focus } : null);
            return;
        }

        if (searchClearBtn) searchClearBtn.classList.remove('hidden');

        var parsed = parseSearchQuery(lastSearchQuery);
        lastParsedQuery = parsed;
        var matches = [];
        searchableElements.forEach(function (item) {
            var scored = scoreItem(item, parsed);
            if (!scored || !scored.matched) return;
            matches.push({
                item: item,
                score: scored.score || 0,
                kind: scored.kind || 'contains',
                token: scored.token || '',
                snippet: excerptFor(item, parsed, scored)
            });
        });

        matches.sort(function (a, b) {
            if (b.score !== a.score) return b.score - a.score;
            var kindDiff = (KIND_RANK[a.kind] || 9) - (KIND_RANK[b.kind] || 9);
            if (kindDiff !== 0) return kindDiff;
            return sectionRank(a.item.sectionId) - sectionRank(b.item.sectionId);
        });
        lastMatches = matches;

        var highlightSource = visibleSearchMatches();
        var highlightLimit = Math.min(highlightSource.length, 40);
        var i;
        for (i = 0; i < highlightLimit; i++) {
            highlightNeedles(highlightSource[i].item.element, [lastSearchQuery, highlightSource[i].token].concat(parsed.words, parsed.phrases));
        }

        renderSearchResults();
        // Only switch to the vertical floating bar when a preset/jump explicitly requests it.
        // Typed search stays as the inline sticky bar + dropdown list.
        if (options.openDock) {
            syncFloatingSearch({ open: true, focus: !!options.focus });
        } else {
            syncFloatingSearch();
        }
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    if (globalSearchInput) {
        initSearchIndex();
        updateFuzzyToggle();

        if (searchFuzzyToggle) {
            searchFuzzyToggle.addEventListener('click', function () {
                fuzzyEnabled = !fuzzyEnabled;
                writeFuzzyPref(fuzzyEnabled);
                updateFuzzyToggle();
                invalidatePresetCountCache();
                renderRoleShortcuts();
                if (globalSearchInput.value.trim().length >= 2) {
                    var wasDocked = !!(window.TicketDossierFloatingSearch && window.TicketDossierFloatingSearch.isOpen && window.TicketDossierFloatingSearch.isOpen());
                    performSearch(globalSearchInput.value.trim(), wasDocked ? { openDock: true, focus: false } : {});
                }
            });
        }

        globalSearchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                // Keep dropdown/inline behavior for typed search.
                performSearch(globalSearchInput.value.trim());
            }, 220);
        });

        globalSearchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var display = visibleSearchMatches();
                if (display.length === 0) return;
                if (e.shiftKey) {
                    goToPrevMatch();
                } else if (activeMatchIndex >= 0) {
                    goToNextMatch();
                } else {
                    goToVisibleMatch(0);
                }
                return;
            }
            if (e.key === 'ArrowDown' && (e.altKey || e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                goToNextMatch();
                return;
            }
            if (e.key === 'ArrowUp' && (e.altKey || e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                goToPrevMatch();
            }
        });

        searchClearBtn && searchClearBtn.addEventListener('click', function () {
            clearSearchState({ keepFocus: true });
        });

        function clearSearchState(options) {
            options = options || {};
            if (globalSearchInput) globalSearchInput.value = '';
            clearHighlights();
            lastMatches = [];
            lastParsedQuery = null;
            lastSearchQuery = '';
            activeMatchIndex = -1;
            if (searchResultsEl) {
                searchResultsEl.classList.add('hidden');
                searchResultsEl.classList.remove('no-results');
                searchResultsEl.innerHTML = '';
            }
            if (searchClearBtn) searchClearBtn.classList.add('hidden');
            if (presetForm && !presetForm.classList.contains('hidden')) {
                setPresetFormOpen(false);
            }
            syncFloatingSearch();
            if (options.keepFocus && globalSearchInput) {
                globalSearchInput.focus();
            }
        }

        if (presetManageBtn) {
            presetManageBtn.addEventListener('click', function () {
                var open = presetForm && !presetForm.classList.contains('hidden');
                setPresetFormOpen(!open);
            });
        }
        if (presetForm) {
            presetForm.addEventListener('submit', handlePresetSubmit);
        }
        if (presetCancelBtn) {
            presetCancelBtn.addEventListener('click', function () {
                if (presetForm) presetForm.reset();
                setPresetFormOpen(false);
            });
        }

        if ('IntersectionObserver' in window && globalSearchContainer) {
            var searchSentinel = document.createElement('div');
            searchSentinel.setAttribute('aria-hidden', 'true');
            searchSentinel.style.height = '1px';
            searchSentinel.style.marginTop = '-1px';
            globalSearchContainer.parentNode.insertBefore(searchSentinel, globalSearchContainer);
            var searchObserver = new IntersectionObserver(function (entries) {
                if (globalSearchContainer.getAttribute('data-mode') === 'docked') {
                    globalSearchContainer.classList.remove('is-stuck');
                    return;
                }
                globalSearchContainer.classList.toggle('is-stuck', !entries[0].isIntersecting);
            });
            searchObserver.observe(searchSentinel);
        }

        window.TicketDossierSearchNav = {
            next: goToNextMatch,
            prev: goToPrevMatch,
            jumpToIndex: goToVisibleMatch,
            clear: clearSearchState,
            getState: function () {
                return {
                    query: lastSearchQuery,
                    matchCount: visibleSearchMatches().length,
                    activeIndex: activeMatchIndex
                };
            }
        };
    } else if (searchJumpsEl) {
        renderRoleShortcuts();
    }

    var editDetails = document.getElementById('edit-details');
    if (editDetails) {
        if (window.location.hash === '#edit-details') {
            editDetails.open = true;
        }
        var ownerSelect = document.getElementById('owner-user-id');
        var ownerNameInput = document.getElementById('owner-name');
        if (ownerSelect && ownerNameInput) {
            ownerSelect.addEventListener('change', function () {
                var option = ownerSelect.options[ownerSelect.selectedIndex];
                var name = option ? (option.getAttribute('data-display-name') || '') : '';
                if (name !== '') {
                    ownerNameInput.value = name;
                }
            });
        }
    }

    var importZip = document.getElementById('import-zip');
    if (importZip && window.location.hash === '#import-zip') {
        importZip.open = true;
    }
})();
