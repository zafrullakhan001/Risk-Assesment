(() => {
  const dialog = document.getElementById('sharepoint-catalog-compare-dialog');
  const openBtn = document.getElementById('sharepoint-catalog-compare-open');
  const searchRoot = document.getElementById('sharepoint-search');
  if (!dialog || typeof dialog.showModal !== 'function' || !searchRoot) return;

  const SP = window.RiskRegisterSharePoint || {};
  const escapeHtml =
    SP.escapeHtml ||
    ((value) =>
      String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;'));
  const catalogBadgeHtml = SP.catalogBadgeHtml || ((label) => escapeHtml(label || ''));
  const formatModified = SP.formatModified || ((value) => String(value || '').trim() || '—');

  if (typeof SP.bindWorkspaceDialog === 'function') {
    SP.bindWorkspaceDialog(dialog);
  }

  const titleEl = document.getElementById('sharepoint-catalog-compare-title');
  const subEl = document.getElementById('sharepoint-catalog-compare-sub');
  const legendEl = document.getElementById('sharepoint-catalog-compare-legend');
  const rowsEl = document.getElementById('sharepoint-catalog-compare-rows');
  const metaEl = document.getElementById('sharepoint-catalog-compare-meta');
  const pagerEl = document.getElementById('sharepoint-catalog-compare-pager');
  const pageLabel = document.getElementById('sharepoint-catalog-compare-page-label');
  const searchWrap = document.getElementById('sharepoint-catalog-compare-search-wrap');
  const searchInput = document.getElementById('sharepoint-catalog-compare-search');
  const searchClear = document.getElementById('sharepoint-catalog-compare-search-clear');
  const sortEl = document.getElementById('sharepoint-catalog-compare-sort');
  const dirEl = document.getElementById('sharepoint-catalog-compare-dir');
  const perEl = document.getElementById('sharepoint-catalog-compare-per');
  const leftHead = document.getElementById('sharepoint-catalog-compare-left-head');
  const rightHead = document.getElementById('sharepoint-catalog-compare-right-head');
  const refreshBtn = document.getElementById('sharepoint-catalog-compare-refresh');
  const closeBtn = document.getElementById('sharepoint-catalog-compare-close');
  const columnsPicker = document.getElementById('sharepoint-catalog-compare-columns-picker');
  const exportCsvBtn = document.getElementById('sharepoint-catalog-compare-export-csv');
  const scopesRoot = document.getElementById('sharepoint-search-scopes');
  const typeChipsRoot = document.getElementById('sharepoint-catalog-compare-type-chips');

  const COLUMN_PREF_KEY = 'riskregister_sp_catalog_compare_columns';
  const TOGGLE_COLUMNS = ['items', 'files', 'folders', 'modified', 'diff', 'actions'];
  const readHiddenColumns = () => {
    try {
      const saved = JSON.parse(localStorage.getItem(COLUMN_PREF_KEY) || '[]');
      return new Set(Array.isArray(saved) ? saved.filter((column) => TOGGLE_COLUMNS.includes(column)) : []);
    } catch {
      return new Set();
    }
  };
  const hiddenColumns = readHiddenColumns();

  const applyHiddenColumns = () => {
    dialog.setAttribute('data-hidden-cols', [...hiddenColumns].join(' '));
    columnsPicker?.querySelectorAll('input[data-col-toggle]').forEach((input) => {
      const column = input.getAttribute('data-col-toggle') || '';
      input.checked = !hiddenColumns.has(column);
    });
  };

  const saveHiddenColumns = () => {
    try {
      localStorage.setItem(COLUMN_PREF_KEY, JSON.stringify([...hiddenColumns]));
    } catch {
      /* ignore */
    }
  };

  const readPrefs = () => {
    if (typeof SP.readSearchPrefs === 'function') return SP.readSearchPrefs();
    return {
      wordMode: localStorage.getItem('riskregister_sp_search_word_mode') === 'or' ? 'or' : 'and',
      fuzzy: localStorage.getItem('riskregister_sp_search_fuzzy') === '1',
      deep: localStorage.getItem('riskregister_sp_search_deep') !== '0',
    };
  };

  const prefs = readPrefs();
  const state = {
    left: '',
    right: '',
    query: '',
    types: new Set(),
    exts: new Set(),
    wordMode: prefs.wordMode === 'or' ? 'or' : 'and',
    fuzzy: !!prefs.fuzzy,
    deep: prefs.deep !== false,
    matchScope: 'all',
    presence: 'any',
    page: 1,
    perPage: 50,
    sort: 'name',
    dir: 'asc',
    busy: false,
    lastKeys: [],
  };

  let abortController = null;
  const compareCache = {
    left: '',
    right: '',
    archived: '',
    rows: null,
    leftMeta: null,
    rightMeta: null,
  };

  const searchIsActive = () => !!(state.query || state.types.size || state.exts.size);

  const canFilterLocally = () =>
    typeof SP.catalogIndexHasSources === 'function' &&
    typeof SP.catalogProjectMatches === 'function' &&
    typeof SP.getIndexedCatalogProject === 'function' &&
    SP.catalogIndexHasSources([state.left, state.right]);

  const cacheKeyMatches = () =>
    compareCache.rows &&
    compareCache.left === state.left &&
    compareCache.right === state.right &&
    compareCache.archived === (searchRoot.getAttribute('data-show-archived') === '1' ? '1' : '');

  const sortCompareRows = (rows) => {
    const dir = state.dir === 'desc' ? -1 : 1;
    const rank = { both: 0, left: 1, right: 2 };
    return [...rows].sort((left, right) => {
      if (state.sort === 'presence') {
        const diff = (rank[left.presence] ?? 9) - (rank[right.presence] ?? 9);
        if (diff) return state.dir === 'desc' ? -diff : diff;
      } else if (state.sort === 'items') {
        const leftCount = Number(left.left?.item_count || 0) + Number(left.right?.item_count || 0);
        const rightCount = Number(right.left?.item_count || 0) + Number(right.right?.item_count || 0);
        if (leftCount !== rightCount) return (leftCount - rightCount) * dir;
      } else if (state.sort === 'modified') {
        const leftMod = String(left.left?.last_modified || left.right?.last_modified || '');
        const rightMod = String(right.left?.last_modified || right.right?.last_modified || '');
        if (leftMod !== rightMod) return leftMod.localeCompare(rightMod) * dir;
      }
      return String(left.name_key || '').localeCompare(String(right.name_key || ''), undefined, {
        sensitivity: 'base',
      }) * (state.sort === 'name' || state.sort === 'presence' || state.sort === 'items' || state.sort === 'modified' ? dir : 1);
    });
  };

  const paintComparePayload = (payload) => {
    const leftTitle = payload.left?.title || catalogTitle(state.left);
    const rightTitle = payload.right?.title || catalogTitle(state.right);
    if (titleEl) titleEl.textContent = 'Compare catalogs';
    if (subEl) {
      subEl.textContent = `${leftTitle} · ${payload.left?.project_count || 0} projects  vs  ${rightTitle} · ${
        payload.right?.project_count || 0
      } projects`;
    }
    renderTotals(payload.totals || {}, payload.presence || state.presence);
    renderRows(payload);
    renderPager(payload);
  };

  const applyLocalCompare = () => {
    const spec = {
      query: state.query,
      types: [...state.types],
      extensions: [...state.exts],
      wordMode: state.wordMode,
      fuzzy: state.fuzzy,
      deep: state.deep,
      matchScope: state.matchScope,
    };
    let rows = compareCache.rows || [];
    if (searchIsActive()) {
      rows = rows.filter((row) => {
        const left = row.left ? SP.getIndexedCatalogProject(state.left, row.left.project_name) : null;
        const right = row.right ? SP.getIndexedCatalogProject(state.right, row.right.project_name) : null;
        return SP.catalogProjectMatches(left, spec) || SP.catalogProjectMatches(right, spec);
      });
    }
    const totals = { all: 0, in_both: 0, only_left: 0, only_right: 0 };
    rows.forEach((row) => {
      totals.all += 1;
      if (row.presence === 'both') totals.in_both += 1;
      else if (row.presence === 'left') totals.only_left += 1;
      else if (row.presence === 'right') totals.only_right += 1;
    });
    const visible =
      state.presence === 'any' ? rows : rows.filter((row) => row.presence === state.presence);
    const sorted = sortCompareRows(visible);
    const pageCount = Math.max(1, Math.ceil(sorted.length / state.perPage));
    if (state.page > pageCount) state.page = pageCount;
    const start = (state.page - 1) * state.perPage;
    paintComparePayload({
      left: compareCache.leftMeta,
      right: compareCache.rightMeta,
      rows: sorted.slice(start, start + state.perPage),
      totals,
      presence: state.presence,
      page: state.page,
      per_page: state.perPage,
      page_count: pageCount,
      row_count: sorted.length,
    });
  };

  const catalogApiUrl = (action, extra = {}) => {
    if (typeof SP.catalogApiUrl === 'function') {
      return SP.catalogApiUrl(action, extra);
    }
    const base = (searchRoot.getAttribute('data-api-base') || 'sharepoint.php').trim() || 'sharepoint.php';
    const token = (searchRoot.getAttribute('data-share-token') || '').trim();
    const params = new URLSearchParams();
    params.set('action', String(action || ''));
    if (token) params.set('t', token);
    Object.entries(extra || {}).forEach(([key, value]) => {
      if (value === undefined || value === null || String(value) === '') return;
      params.set(key, String(value));
    });
    return `${base}?${params.toString()}`;
  };

  const selectedCatalogKeys = (fromEvent = null) => {
    const fromDetail = Array.isArray(fromEvent?.detail?.keys) ? fromEvent.detail.keys.map(String) : [];
    if (fromDetail.length) return fromDetail.filter(Boolean);
    if (!scopesRoot) {
      const fallback = (searchRoot.getAttribute('data-source-key') || '').trim();
      return fallback ? [fallback] : [];
    }
    return Array.from(scopesRoot.querySelectorAll('.sharepoint-scope-check:checked'))
      .map((input) => String(input.value || '').trim())
      .filter(Boolean);
  };

  const catalogTitle = (key) => {
    try {
      const sources = JSON.parse(searchRoot.dataset.sources || '[]');
      const match = Array.isArray(sources)
        ? sources.find((src) => String(src.source_key || '') === String(key || ''))
        : null;
      return String(match?.title || key || '');
    } catch {
      return String(key || '');
    }
  };

  const syncOpenButton = (keys = selectedCatalogKeys()) => {
    if (!openBtn) return;
    const ready = keys.length === 2;
    openBtn.disabled = !ready;
    openBtn.classList.toggle('is-ready', ready);
    openBtn.title = ready
      ? `Compare ${catalogTitle(keys[0])} and ${catalogTitle(keys[1])}`
      : 'Select exactly two catalogs to compare project folders';
    state.lastKeys = keys;
  };

  const setBusy = (busy) => {
    state.busy = !!busy;
    if (!refreshBtn) return;
    refreshBtn.disabled = state.busy;
    refreshBtn.classList.toggle('is-refreshing', state.busy);
    refreshBtn.setAttribute('aria-busy', state.busy ? 'true' : 'false');
  };

  const presenceLabel = (presence) => {
    if (presence === 'both') return 'In both';
    if (presence === 'left') return 'Only left';
    if (presence === 'right') return 'Only right';
    return presence;
  };

  const pillClass = (presence) => {
    if (presence === 'both') return 'all';
    if (presence === 'left') return 'left';
    if (presence === 'right') return 'right';
    return 'shared';
  };

  const sideHtml = (side, sourceKey, sourceTitle) => {
    if (!side) {
      return `<article class="sp-cc-side is-empty" aria-label="Not in this catalog">Not in this catalog</article>`;
    }
    const badge = catalogBadgeHtml(sourceTitle, sourceKey);
    const modified = side.last_modified ? formatModified(side.last_modified) : 'No date';
    return `<article class="sp-cc-side">
      <h4 class="sp-cc-name">${escapeHtml(side.project_name || '')}</h4>
      ${badge ? `<div class="sp-cc-catalog">${badge}</div>` : ''}
      <div class="sp-cc-fields">
        <span class="sp-cc-field" data-col="items"><b>Items</b> ${Number(side.item_count || 0)}</span>
        <span class="sp-cc-field" data-col="files"><b>Files</b> ${Number(side.file_count || 0)}</span>
        <span class="sp-cc-field" data-col="folders"><b>Folders</b> ${Number(side.folder_count || 0)}</span>
        <span class="sp-cc-field" data-col="modified"><b>Modified</b> ${escapeHtml(modified)}</span>
      </div>
    </article>`;
  };

  const rowActionsHtml = (row) => {
    const left = row.left;
    const right = row.right;
    const buttons = [];
    if (left && right) {
      buttons.push(
        `<button type="button" class="button button-primary sp-cc-files" data-left-name="${escapeHtml(
          left.project_name || ''
        )}" data-right-name="${escapeHtml(right.project_name || '')}" title="Compare files and folders">Files</button>`
      );
    }
    if (left) {
      buttons.push(
        `<button type="button" class="button ghost sp-cc-open" data-side="left" data-project-name="${escapeHtml(
          left.project_name || ''
        )}" title="Open left project">Left</button>`
      );
      if (left.folder_url) {
        buttons.push(
          `<a class="button ghost sp-cc-ext" href="${escapeHtml(left.folder_url)}" target="_blank" rel="noopener noreferrer" title="Open left in SharePoint" aria-label="Open left in SharePoint">↗</a>`
        );
      }
    }
    if (right) {
      buttons.push(
        `<button type="button" class="button ghost sp-cc-open" data-side="right" data-project-name="${escapeHtml(
          right.project_name || ''
        )}" title="Open right project">Right</button>`
      );
      if (right.folder_url) {
        buttons.push(
          `<a class="button ghost sp-cc-ext" href="${escapeHtml(right.folder_url)}" target="_blank" rel="noopener noreferrer" title="Open right in SharePoint" aria-label="Open right in SharePoint">↗</a>`
        );
      }
    }
    return `<div class="sp-cc-row-tools">
      <span data-col="diff" class="sp-diff-pill sp-diff-pill--${pillClass(row.presence)}">${escapeHtml(
        presenceLabel(row.presence)
      )}</span>
      <span data-col="actions" class="sp-cc-actions">${buttons.join('')}</span>
    </div>`;
  };

  const renderRows = (payload) => {
    const rows = Array.isArray(payload.rows) ? payload.rows : [];
    const leftTitle = payload.left?.title || catalogTitle(state.left);
    const rightTitle = payload.right?.title || catalogTitle(state.right);
    if (leftHead) leftHead.textContent = leftTitle;
    if (rightHead) rightHead.textContent = rightTitle;
    if (!rows.length) {
      rowsEl.innerHTML = `<p class="sharepoint-dialog-empty">No project folders match this comparison.</p>`;
      return;
    }
    rowsEl.innerHTML = rows
      .map(
        (row) => `<article class="sp-cc-row" data-presence="${escapeHtml(row.presence || '')}">
          ${sideHtml(row.left, payload.left?.source_key || state.left, leftTitle)}
          ${sideHtml(row.right, payload.right?.source_key || state.right, rightTitle)}
          ${rowActionsHtml(row)}
        </article>`
      )
      .join('');
  };

  const renderTotals = (totals = {}, presence = 'any') => {
    if (!legendEl) return;
    legendEl.hidden = false;
    legendEl.querySelectorAll('[data-total]').forEach((el) => {
      const key = el.getAttribute('data-total') || '';
      el.textContent = String(Number(totals[key] || 0));
    });
    legendEl.querySelectorAll('.sp-cc-total[data-presence]').forEach((btn) => {
      const active = (btn.getAttribute('data-presence') || 'any') === presence;
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  };

  const renderPager = (payload) => {
    const page = Number(payload.page || 1);
    const pageCount = Number(payload.page_count || 1);
    const rowCount = Number(payload.row_count || 0);
    if (pagerEl) pagerEl.hidden = pageCount <= 1 && rowCount <= Number(payload.per_page || state.perPage);
    if (pageLabel) pageLabel.textContent = `Page ${page} of ${pageCount}`;
    const prev = document.getElementById('sharepoint-catalog-compare-prev');
    const next = document.getElementById('sharepoint-catalog-compare-next');
    if (prev) prev.disabled = page <= 1;
    if (next) next.disabled = page >= pageCount;
    const from = rowCount === 0 ? 0 : (page - 1) * Number(payload.per_page || state.perPage) + 1;
    const to = Math.min(rowCount, page * Number(payload.per_page || state.perPage));
    if (metaEl) {
      const bits = [];
      if (state.query) bits.push(`“${state.query}”`);
      if (state.types.size) bits.push(`type:${[...state.types].join(',')}`);
      if (state.exts.size) bits.push(`ext:${[...state.exts].join(',')}`);
      if ((state.query.match(/\S+/g) || []).length > 1) bits.push(state.wordMode.toUpperCase());
      if (state.fuzzy) bits.push('Fuzzy');
      if (state.deep || state.matchScope === 'files') bits.push('Deep files');
      else if (state.query || state.types.size || state.exts.size) bits.push('folder names');
      if (state.matchScope !== 'all') bits.push(state.matchScope);
      const filterNote = bits.length ? ` · ${bits.join(' · ')}` : '';
      metaEl.textContent = rowCount
        ? `Showing ${from}–${to} of ${rowCount} project folders${filterNote}`
        : `No matching project folders${filterNote}`;
    }
  };

  const load = async ({ fresh = false } = {}) => {
    if (!state.left || !state.right) return;
    if (fresh) {
      compareCache.rows = null;
    }
    if (cacheKeyMatches() && (!searchIsActive() || canFilterLocally())) {
      applyLocalCompare();
      return;
    }
    if (abortController) {
      try {
        abortController.abort();
      } catch {
        /* ignore */
      }
    }
    abortController = new AbortController();
    setBusy(true);
    if (subEl) subEl.textContent = 'Loading comparison…';
    if (rowsEl && !rowsEl.querySelector('.sp-cc-row')) {
      rowsEl.innerHTML = `<p class="sharepoint-dialog-empty">⏳ Loading…</p>`;
    }
    const cacheUnfiltered = canFilterLocally() || !searchIsActive();
    const extra = {
      left: state.left,
      right: state.right,
      q: cacheUnfiltered ? '' : state.query,
      presence: cacheUnfiltered ? 'any' : state.presence,
      page: cacheUnfiltered ? '1' : String(state.page),
      per: cacheUnfiltered ? '0' : String(state.perPage),
      sort: state.sort,
      dir: state.dir,
      mode: state.wordMode,
      fuzzy: state.fuzzy ? '1' : '0',
      deep: state.deep ? '1' : '0',
      scope: state.matchScope,
      type: cacheUnfiltered ? '' : [...state.types].join(','),
      ext: cacheUnfiltered ? '' : [...state.exts].join(','),
    };
    if (cacheUnfiltered) extra.all = '1';
    if (searchRoot.getAttribute('data-show-archived') === '1') extra.archived = '1';
    if (fresh) extra._ts = String(Date.now());
    try {
      const response = await fetch(catalogApiUrl('catalog_compare', extra), {
        credentials: 'same-origin',
        cache: 'no-store',
        signal: abortController.signal,
        headers: { Accept: 'application/json', 'Cache-Control': 'no-cache' },
      });
      const payload = await response.json();
      if (!response.ok || !payload?.ok) {
        throw new Error(payload?.error || 'Unable to compare catalogs.');
      }
      if (cacheUnfiltered) {
        compareCache.left = state.left;
        compareCache.right = state.right;
        compareCache.archived = extra.archived === '1' ? '1' : '';
        compareCache.rows = Array.isArray(payload.rows) ? payload.rows : [];
        compareCache.leftMeta = payload.left || null;
        compareCache.rightMeta = payload.right || null;
        applyLocalCompare();
        return;
      }
      paintComparePayload(payload);
    } catch (error) {
      if (error?.name === 'AbortError') return;
      if (subEl) subEl.textContent = error.message || 'Compare failed.';
      rowsEl.innerHTML = `<p class="sharepoint-dialog-empty">${escapeHtml(error.message || 'Compare failed.')}</p>`;
    } finally {
      setBusy(false);
    }
  };

  const openCompareCatalogs = (keys = selectedCatalogKeys()) => {
    if (keys.length !== 2) return;
    state.left = keys[0];
    state.right = keys[1];
    state.page = 1;
    applyPrefsToState();
    if (searchInput) searchInput.value = state.query;
    if (sortEl) sortEl.value = state.sort;
    if (dirEl) dirEl.value = state.dir;
    if (perEl) perEl.value = String(state.perPage);
    syncChipUi();
    syncSearchModes?.(state.query);
    dialog.__spPrepareWorkspace?.();
    if (!dialog.open) dialog.showModal();
    dialog.__spPrepareWorkspace?.();
    SP.playWorkspaceDialogEnter?.(dialog);
    load();
  };

  openBtn?.addEventListener('click', () => {
    const keys = selectedCatalogKeys();
    if (keys.length !== 2) return;
    openCompareCatalogs(keys);
  });

  closeBtn?.addEventListener('click', () => dialog.close());
  refreshBtn?.addEventListener('click', () => {
    if (state.busy) return;
    load({ fresh: true });
  });

  columnsPicker?.querySelectorAll('input[data-col-toggle]').forEach((input) => {
    input.addEventListener('change', () => {
      const column = input.getAttribute('data-col-toggle') || '';
      if (!TOGGLE_COLUMNS.includes(column)) return;
      if (input.checked) hiddenColumns.delete(column);
      else hiddenColumns.add(column);
      saveHiddenColumns();
      applyHiddenColumns();
    });
  });
  columnsPicker?.querySelector('.sp-compare-columns-menu')?.addEventListener('click', (event) => {
    event.stopPropagation();
  });
  columnsPicker?.querySelector('[data-columns-close]')?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    columnsPicker.open = false;
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && columnsPicker?.open) columnsPicker.open = false;
  });

  const csvEscape = (value) => {
    let text = String(value ?? '');
    if (/^[=+\-@\t\r]/.test(text)) text = `'${text}`;
    if (/[",\n\r]/.test(text)) return `"${text.replace(/"/g, '""')}"`;
    return text;
  };

  const exportFilteredCsv = async () => {
    if (!state.left || !state.right || state.busy || exportCsvBtn?.disabled) return;
    const leftTitle = compareCache.leftMeta?.title || catalogTitle(state.left);
    const rightTitle = compareCache.rightMeta?.title || catalogTitle(state.right);
    const columns = [
      { key: 'left_project', label: `${leftTitle} project`, value: (row) => row.left?.project_name || '' },
      { key: 'right_project', label: `${rightTitle} project`, value: (row) => row.right?.project_name || '' },
      { key: 'left_items', label: `${leftTitle} items`, group: 'items', value: (row) => row.left?.item_count ?? '' },
      { key: 'right_items', label: `${rightTitle} items`, group: 'items', value: (row) => row.right?.item_count ?? '' },
      { key: 'left_files', label: `${leftTitle} files`, group: 'files', value: (row) => row.left?.file_count ?? '' },
      { key: 'right_files', label: `${rightTitle} files`, group: 'files', value: (row) => row.right?.file_count ?? '' },
      { key: 'left_folders', label: `${leftTitle} folders`, group: 'folders', value: (row) => row.left?.folder_count ?? '' },
      { key: 'right_folders', label: `${rightTitle} folders`, group: 'folders', value: (row) => row.right?.folder_count ?? '' },
      { key: 'left_modified', label: `${leftTitle} modified`, group: 'modified', value: (row) => row.left?.last_modified || '' },
      { key: 'right_modified', label: `${rightTitle} modified`, group: 'modified', value: (row) => row.right?.last_modified || '' },
      { key: 'diff', label: 'Diff', group: 'diff', value: (row) => presenceLabel(row.presence) },
      { key: 'left_url', label: `${leftTitle} SharePoint URL`, group: 'actions', value: (row) => row.left?.folder_url || '' },
      { key: 'right_url', label: `${rightTitle} SharePoint URL`, group: 'actions', value: (row) => row.right?.folder_url || '' },
    ].filter((column) => !column.group || !hiddenColumns.has(column.group));

    exportCsvBtn.disabled = true;
    const originalLabel = exportCsvBtn.textContent;
    exportCsvBtn.textContent = 'Exporting…';
    try {
      const extra = {
        left: state.left,
        right: state.right,
        q: state.query,
        presence: state.presence,
        page: '1',
        per: '0',
        all: '1',
        sort: state.sort,
        dir: state.dir,
        mode: state.wordMode,
        fuzzy: state.fuzzy ? '1' : '0',
        deep: state.deep ? '1' : '0',
        scope: state.matchScope,
        type: [...state.types].join(','),
        ext: [...state.exts].join(','),
      };
      if (searchRoot.getAttribute('data-show-archived') === '1') extra.archived = '1';
      const response = await fetch(catalogApiUrl('catalog_compare', extra), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', 'Cache-Control': 'no-cache' },
      });
      const payload = await response.json();
      if (!response.ok || !payload?.ok) throw new Error(payload?.error || 'Unable to export comparison.');

      const lines = [columns.map((column) => csvEscape(column.label)).join(',')];
      (Array.isArray(payload.rows) ? payload.rows : []).forEach((row) => {
        lines.push(columns.map((column) => csvEscape(column.value(row))).join(','));
      });
      const blob = new Blob([`\uFEFF${lines.join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `catalog-compare-${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch (error) {
      if (metaEl) metaEl.textContent = error?.message || 'Unable to export comparison.';
    } finally {
      exportCsvBtn.disabled = false;
      exportCsvBtn.textContent = originalLabel || 'Export CSV';
    }
  };
  exportCsvBtn?.addEventListener('click', exportFilteredCsv);

  const syncChipUi = () => {
    typeChipsRoot?.querySelectorAll('[data-type-chip]').forEach((btn) => {
      const key = String(btn.getAttribute('data-type-chip') || '').toLowerCase();
      const active = state.types.has(key);
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    searchWrap?.querySelectorAll('.sp-dialog-chip[data-ext]').forEach((btn) => {
      const key = String(btn.getAttribute('data-ext') || '')
        .toLowerCase()
        .replace(/^\./, '');
      const active = state.exts.has(key);
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    searchWrap?.querySelectorAll('[data-match-scope]').forEach((btn) => {
      const active = (btn.getAttribute('data-match-scope') || 'all') === state.matchScope;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    if (searchClear) {
      const active = !!(state.query || state.types.size || state.exts.size);
      searchClear.hidden = !active;
    }
  };

  const applyPrefsToState = () => {
    const next = readPrefs();
    state.wordMode = next.wordMode === 'or' ? 'or' : 'and';
    state.fuzzy = !!next.fuzzy;
    state.deep = next.deep !== false;
  };

  const applySearchQuery = (next, { reload = true } = {}) => {
    const query = String(next ?? '').trim();
    const changed = query !== state.query;
    state.query = query;
    if (searchInput && searchInput.value !== query) searchInput.value = query;
    syncChipUi();
    syncSearchModes?.(query);
    if (!reload || !changed) return;
    state.page = 1;
    load();
  };

  const resetFilters = ({ reload = false } = {}) => {
    state.query = '';
    state.types.clear();
    state.exts.clear();
    state.matchScope = 'all';
    if (searchInput) searchInput.value = '';
    syncChipUi();
    syncSearchModes?.('');
    if (reload) {
      state.page = 1;
      load();
    }
  };

  let syncSearchModes = () => readPrefs();
  if (typeof SP.bindDialogSearchModes === 'function' && searchWrap) {
    syncSearchModes = SP.bindDialogSearchModes(searchWrap, () => {
      applyPrefsToState();
      state.page = 1;
      if (dialog.open) load();
    });
  }
  if (typeof SP.bindDialogSavedPresets === 'function') {
    SP.bindDialogSavedPresets('sharepoint-catalog-compare-saved-chips', {
      getActiveQuery: () => state.query,
      onApply: (query) => applySearchQuery(query),
    });
  }

  document.getElementById('sharepoint-catalog-compare-search-run')?.addEventListener('click', () => {
    applySearchQuery(searchInput?.value || '');
  });
  searchInput?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      applySearchQuery('');
      return;
    }
    if (event.key !== 'Enter') return;
    event.preventDefault();
    applySearchQuery(searchInput.value || '');
  });
  searchInput?.addEventListener('search', () => {
    applySearchQuery(searchInput.value || '');
  });
  searchInput?.addEventListener('input', () => {
    if (String(searchInput.value || '').trim() !== '') return;
    applySearchQuery('');
  });
  dialog.addEventListener('close', () => {
    resetFilters({ reload: false });
    state.presence = 'any';
    state.page = 1;
  });

  typeChipsRoot?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-type-chip]');
    if (!btn || !typeChipsRoot.contains(btn)) return;
    const key = String(btn.getAttribute('data-type-chip') || '').toLowerCase();
    if (!key) return;
    if (state.types.has(key)) state.types.delete(key);
    else state.types.add(key);
    syncChipUi();
    state.page = 1;
    load();
  });
  searchWrap?.querySelectorAll('.sp-dialog-chip[data-ext]').forEach((chip) => {
    chip.addEventListener('click', () => {
      const key = String(chip.getAttribute('data-ext') || '')
        .toLowerCase()
        .replace(/^\./, '');
      if (!key) return;
      if (state.exts.has(key)) state.exts.delete(key);
      else state.exts.add(key);
      syncChipUi();
      state.page = 1;
      load();
    });
  });
  searchWrap?.querySelectorAll('[data-match-scope]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const next = btn.getAttribute('data-match-scope') || 'all';
      state.matchScope = ['all', 'names', 'files', 'people'].includes(next) ? next : 'all';
      syncChipUi();
      state.page = 1;
      load();
    });
  });
  searchClear?.addEventListener('click', () => {
    resetFilters({ reload: dialog.open });
  });

  sortEl?.addEventListener('change', () => {
    state.sort = sortEl.value || 'name';
    state.page = 1;
    load();
  });
  dirEl?.addEventListener('change', () => {
    state.dir = dirEl.value === 'desc' ? 'desc' : 'asc';
    state.page = 1;
    load();
  });
  perEl?.addEventListener('change', () => {
    state.perPage = Number(perEl.value) || 50;
    state.page = 1;
    load();
  });

  legendEl?.addEventListener('click', (event) => {
    const btn = event.target.closest('.sp-cc-total[data-presence]');
    if (!btn) return;
    state.presence = btn.getAttribute('data-presence') || 'any';
    state.page = 1;
    load();
  });

  document.getElementById('sharepoint-catalog-compare-prev')?.addEventListener('click', () => {
    if (state.page <= 1) return;
    state.page -= 1;
    load();
  });
  document.getElementById('sharepoint-catalog-compare-next')?.addEventListener('click', () => {
    state.page += 1;
    load();
  });

  rowsEl?.addEventListener('click', (event) => {
    const filesBtn = event.target.closest('.sp-cc-files');
    if (filesBtn) {
      event.preventDefault();
      const leftName = filesBtn.getAttribute('data-left-name') || '';
      const rightName = filesBtn.getAttribute('data-right-name') || '';
      if (!leftName || !rightName || typeof SP.openCompare !== 'function') return;
      SP.openCompare([
        { projectName: leftName, sourceKey: state.left },
        { projectName: rightName, sourceKey: state.right },
      ]);
      return;
    }
    const openProjectBtn = event.target.closest('.sp-cc-open');
    if (openProjectBtn) {
      event.preventDefault();
      const side = openProjectBtn.getAttribute('data-side') === 'right' ? 'right' : 'left';
      const name = openProjectBtn.getAttribute('data-project-name') || '';
      const sourceKey = side === 'right' ? state.right : state.left;
      if (name && typeof SP.openProject === 'function') {
        SP.openProject(name, sourceKey);
      }
    }
  });

  window.addEventListener('riskregister:sp-scopes', (event) => {
    const keys = selectedCatalogKeys(event);
    syncOpenButton(keys);
    if (dialog.open && keys.length === 2 && (keys[0] !== state.left || keys[1] !== state.right)) {
      state.left = keys[0];
      state.right = keys[1];
      state.page = 1;
      load();
    }
  });

  scopesRoot?.addEventListener('change', () => syncOpenButton());
  document.getElementById('sharepoint-scopes-all')?.addEventListener('click', () => {
    window.setTimeout(() => syncOpenButton(), 0);
  });
  document.getElementById('sharepoint-scopes-active')?.addEventListener('click', () => {
    window.setTimeout(() => syncOpenButton(), 0);
  });

  window.RiskRegisterSharePoint = Object.assign(window.RiskRegisterSharePoint || {}, {
    openCatalogCompare: openCompareCatalogs,
  });

  applyHiddenColumns();
  syncOpenButton();
})();
