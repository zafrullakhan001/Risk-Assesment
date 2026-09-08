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
    var toggleBtn = document.getElementById('toggle-all-fields');
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
        });
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
    var searchResultsEl = document.getElementById('search-results');
    var globalSearchContainer = document.getElementById('global-search');
    var searchableElements = [];
    var currentHighlights = [];

    function initSearchIndex() {
        searchableElements = [];
        
        // Index all searchable content
        var selectors = [
            '.field-item dd',
            '.field-item dt',
            '.kv dd',
            '.kv dt',
            '.vendor',
            '.pill',
            '.chip',
            '.prose-block p',
            '.qa-item .q',
            '.qa-item .a',
            '.ribbon-node strong',
            '.ribbon-state',
            '.overview-card p',
            '.hero h2',
            '.hero-intro p',
            '.related-list li',
            '.file-list a'
        ];

        selectors.forEach(function (selector) {
            document.querySelectorAll(selector).forEach(function (el) {
                var text = el.textContent.trim();
                if (text && text.length > 0) {
                    var section = el.closest('.panel, .hero, .ribbon');
                    var sectionTitle = '';
                    if (section) {
                        var heading = section.querySelector('h2, h3');
                        sectionTitle = heading ? heading.textContent.trim() : '';
                    }
                    
                    searchableElements.push({
                        element: el,
                        text: text.toLowerCase(),
                        originalText: text,
                        section: section,
                        sectionTitle: sectionTitle,
                        label: getLabelForElement(el)
                    });
                }
            });
        });
    }

    function getLabelForElement(el) {
        // Try to find a label/dt element before this element
        var fieldItem = el.closest('.field-item');
        if (fieldItem) {
            var dt = fieldItem.querySelector('dt');
            if (dt) return dt.textContent.trim();
        }

        var kvItem = el.closest('.kv > div');
        if (kvItem) {
            var kvDt = kvItem.querySelector('dt');
            if (kvDt) return kvDt.textContent.trim();
        }

        if (el.classList.contains('dt') || el.tagName === 'DT') {
            return el.textContent.trim();
        }

        // Check if it's a vendor
        if (el.classList.contains('vendor')) {
            return 'Vendor';
        }

        // Check for QA items
        if (el.classList.contains('q')) {
            return 'Question';
        }
        if (el.classList.contains('a')) {
            return 'Answer';
        }

        // Check ribbon
        if (el.closest('.ribbon-node')) {
            var ribbonNode = el.closest('.ribbon-node');
            var label = ribbonNode.querySelector('.ribbon-label');
            if (label) return label.textContent.trim();
        }

        return 'Content';
    }

    function clearHighlights() {
        currentHighlights.forEach(function (el) {
            var parent = el.parentNode;
            if (parent) {
                parent.replaceChild(document.createTextNode(el.textContent), el);
                parent.normalize();
            }
        });
        currentHighlights = [];
    }

    function highlightText(element, query) {
        var text = element.textContent;
        var lowerText = text.toLowerCase();
        var lowerQuery = query.toLowerCase();
        var index = lowerText.indexOf(lowerQuery);
        
        if (index === -1) return;

        var before = text.substring(0, index);
        var match = text.substring(index, index + query.length);
        var after = text.substring(index + query.length);

        element.innerHTML = '';
        if (before) element.appendChild(document.createTextNode(before));
        
        var mark = document.createElement('span');
        mark.className = 'highlight-match';
        mark.textContent = match;
        element.appendChild(mark);
        currentHighlights.push(mark);
        
        if (after) element.appendChild(document.createTextNode(after));
    }

    function performSearch(query) {
        clearHighlights();

        if (!query || query.length < 2) {
            searchResultsEl.classList.add('hidden');
            searchClearBtn.classList.add('hidden');
            return;
        }

        searchClearBtn.classList.remove('hidden');

        var lowerQuery = query.toLowerCase();
        var matches = [];

        searchableElements.forEach(function (item) {
            if (item.text.indexOf(lowerQuery) !== -1) {
                matches.push(item);
            }
        });

        if (matches.length === 0) {
            searchResultsEl.classList.remove('hidden');
            searchResultsEl.classList.add('no-results');
            searchResultsEl.innerHTML = '<p>No matches found for "' + escapeHtml(query) + '"</p>';
            return;
        }

        // Highlight matches in the page
        matches.forEach(function (match) {
            highlightText(match.element, query);
        });

        // Show results summary
        searchResultsEl.classList.remove('hidden', 'no-results');
        
        var summary = '<p><strong>' + matches.length + '</strong> match' + (matches.length === 1 ? '' : 'es') + ' found. Click to jump:</p>';
        summary += '<ul class="search-match-list">';
        
        // Show first 10 matches
        matches.slice(0, 10).forEach(function (match, idx) {
            var context = match.originalText;
            if (context.length > 80) {
                context = context.substring(0, 77) + '...';
            }
            
            summary += '<li class="search-match-item" data-match-idx="' + idx + '">';
            summary += '<div class="search-match-label">' + escapeHtml(match.label) + ' · ' + escapeHtml(match.sectionTitle) + '</div>';
            summary += '<div class="search-match-context">' + escapeHtml(context) + '</div>';
            summary += '</li>';
        });
        
        if (matches.length > 10) {
            summary += '<li style="padding:6px 8px;color:var(--muted);font-size:11px;">... and ' + (matches.length - 10) + ' more</li>';
        }
        
        summary += '</ul>';
        searchResultsEl.innerHTML = summary;

        // Add click handlers to jump to matches
        searchResultsEl.querySelectorAll('.search-match-item').forEach(function (item) {
            item.addEventListener('click', function () {
                var idx = parseInt(item.getAttribute('data-match-idx'), 10);
                var match = matches[idx];
                if (match && match.element) {
                    match.element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    
                    // Brief pulse effect
                    match.element.style.transition = 'background 0.3s ease';
                    match.element.style.background = 'var(--primary-soft)';
                    setTimeout(function () {
                        match.element.style.background = '';
                    }, 800);
                }
            });
        });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    if (globalSearchInput) {
        initSearchIndex();

        var searchTimer = 0;
        globalSearchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                performSearch(globalSearchInput.value.trim());
            }, 300);
        });

        searchClearBtn && searchClearBtn.addEventListener('click', function () {
            globalSearchInput.value = '';
            clearHighlights();
            searchResultsEl.classList.add('hidden');
            searchClearBtn.classList.add('hidden');
            globalSearchInput.focus();
        });

        // Sticky behavior for search bar
        if ('IntersectionObserver' in window && globalSearchContainer) {
            var searchSentinel = document.createElement('div');
            searchSentinel.setAttribute('aria-hidden', 'true');
            searchSentinel.style.height = '1px';
            searchSentinel.style.marginTop = '-1px';
            globalSearchContainer.parentNode.insertBefore(searchSentinel, globalSearchContainer);
            var searchObserver = new IntersectionObserver(function (entries) {
                globalSearchContainer.classList.toggle('is-stuck', !entries[0].isIntersecting);
            });
            searchObserver.observe(searchSentinel);
        }
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
})();
