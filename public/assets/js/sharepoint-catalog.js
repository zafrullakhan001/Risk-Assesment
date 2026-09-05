(() => {
  const Fuzzy = window.FuzzySearch;
  const escapeHtml = (value) =>
    String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

  const formatModified = (value) => {
    const raw = String(value ?? '').trim();
    if (!raw) return '—';
    if (/^\d{4}-\d{2}-\d{2}/.test(raw)) {
      const date = new Date(raw);
      if (!Number.isNaN(date.getTime())) {
        return date.toLocaleString(undefined, {
          year: 'numeric',
          month: 'short',
          day: 'numeric',
          hour: 'numeric',
          minute: '2-digit',
        });
      }
    }
    return raw;
  };

  const FILE_META = {
    folder: { emoji: '📁', label: 'Folder', tone: 'folder' },
    pdf: { emoji: '📕', label: 'PDF', tone: 'pdf' },
    doc: { emoji: '📘', label: 'Word', tone: 'word' },
    docx: { emoji: '📘', label: 'Word', tone: 'word' },
    rtf: { emoji: '📘', label: 'Word', tone: 'word' },
    xls: { emoji: '📊', label: 'Excel', tone: 'excel' },
    xlsx: { emoji: '📊', label: 'Excel', tone: 'excel' },
    xlsm: { emoji: '📊', label: 'Excel', tone: 'excel' },
    csv: { emoji: '📑', label: 'CSV', tone: 'excel' },
    ppt: { emoji: '📙', label: 'PowerPoint', tone: 'ppt' },
    pptx: { emoji: '📙', label: 'PowerPoint', tone: 'ppt' },
    vsd: { emoji: '📐', label: 'Visio', tone: 'visio' },
    vsdx: { emoji: '📐', label: 'Visio', tone: 'visio' },
    jpg: { emoji: '🖼️', label: 'Image', tone: 'image' },
    jpeg: { emoji: '🖼️', label: 'Image', tone: 'image' },
    png: { emoji: '🖼️', label: 'Image', tone: 'image' },
    gif: { emoji: '🖼️', label: 'Image', tone: 'image' },
    webp: { emoji: '🖼️', label: 'Image', tone: 'image' },
    svg: { emoji: '🖼️', label: 'Image', tone: 'image' },
    bmp: { emoji: '🖼️', label: 'Image', tone: 'image' },
    txt: { emoji: '📝', label: 'Text', tone: 'text' },
    md: { emoji: '📝', label: 'Markdown', tone: 'text' },
    log: { emoji: '📝', label: 'Log', tone: 'text' },
    json: { emoji: '🧾', label: 'JSON', tone: 'code' },
    xml: { emoji: '🧾', label: 'XML', tone: 'code' },
    html: { emoji: '🌐', label: 'HTML', tone: 'code' },
    htm: { emoji: '🌐', label: 'HTML', tone: 'code' },
    zip: { emoji: '📦', label: 'Archive', tone: 'archive' },
    rar: { emoji: '📦', label: 'Archive', tone: 'archive' },
    '7z': { emoji: '📦', label: 'Archive', tone: 'archive' },
    msg: { emoji: '✉️', label: 'Email', tone: 'email' },
    eml: { emoji: '✉️', label: 'Email', tone: 'email' },
    mp4: { emoji: '🎬', label: 'Video', tone: 'media' },
    mov: { emoji: '🎬', label: 'Video', tone: 'media' },
    avi: { emoji: '🎬', label: 'Video', tone: 'media' },
    mp3: { emoji: '🎵', label: 'Audio', tone: 'media' },
    wav: { emoji: '🎵', label: 'Audio', tone: 'media' },
    dwg: { emoji: '🏗️', label: 'CAD', tone: 'cad' },
    dxf: { emoji: '🏗️', label: 'CAD', tone: 'cad' },
    one: { emoji: '📓', label: 'OneNote', tone: 'onenote' },
    onepkg: { emoji: '📓', label: 'OneNote', tone: 'onenote' },
  };

  const fileExtension = (name) => {
    const base = String(name || '').split(/[\\/]/).pop() || '';
    const dot = base.lastIndexOf('.');
    if (dot <= 0 || dot === base.length - 1) return '';
    return base.slice(dot + 1).toLowerCase();
  };

  const resolveMeta = (item) => {
    const type = String(item.item_type || 'file').toLowerCase();
    if (type === 'folder') return FILE_META.folder;
    const ext = fileExtension(item.name);
    return FILE_META[ext] || { emoji: '📄', label: ext ? ext.toUpperCase() : 'File', tone: 'file' };
  };

  const itemKey = (item) => {
    const path = String(item.relative_path || item.name || '')
      .trim()
      .toLowerCase();
    const type = String(item.item_type || 'file').toLowerCase();
    return `${type}::${path}`;
  };

  const fetchProjectDetail = async (projectName, sourceKey = '') => {
    const name = String(projectName || '').trim();
    if (!name) throw new Error('Missing project name.');
    const resolvedSource =
      String(sourceKey || '').trim() ||
      document.getElementById('sharepoint-search')?.getAttribute('data-source-key') ||
      '';
    const sourceQs = resolvedSource ? `&source=${encodeURIComponent(resolvedSource)}` : '';
    const response = await fetch(
      `sharepoint.php?action=project_detail&name=${encodeURIComponent(name)}${sourceQs}`,
      { credentials: 'same-origin', headers: { Accept: 'application/json' } }
    );
    const payload = await response.json();
    if (!response.ok || !payload.ok || !payload.project) {
      throw new Error(payload.error || 'Unable to load project details.');
    }
    return payload.project;
  };

  const selectionKey = (sourceKey, projectName) => `${sourceKey}::${projectName}`;

  /* ---- Project detail dialog ---- */
  const initDialog = () => {
    const dialog = document.getElementById('sharepoint-project-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return null;

    const titleEl = document.getElementById('sharepoint-project-dialog-title');
    const subEl = document.getElementById('sharepoint-project-dialog-sub');
    const actionsEl = document.getElementById('sharepoint-project-dialog-actions');
    const rowsEl = document.getElementById('sharepoint-project-dialog-rows');
    const closeBtn = document.getElementById('sharepoint-project-dialog-close');

    const renderRows = (items) => {
      if (!Array.isArray(items) || items.length === 0) {
        rowsEl.innerHTML =
          '<tr><td colspan="5" class="sharepoint-dialog-empty">🗂️ No files or folders found for this project.</td></tr>';
        return;
      }

      rowsEl.innerHTML = items
        .map((item) => {
          const name = String(item.name || '');
          const url = String(item.web_url || '');
          const path = String(item.relative_path || '');
          const meta = resolveMeta(item);
          const isFolder = String(item.item_type || '').toLowerCase() === 'folder';
          const pathHtml =
            path && path !== name
              ? `<span class="sharepoint-link-path">${escapeHtml(path)}</span>`
              : '';
          const nameInner = `
          <span class="sp-file-icon sp-file-icon--${escapeHtml(meta.tone)}" aria-hidden="true">${meta.emoji}</span>
          <span class="sp-file-copy">
            <span class="sp-file-name">${escapeHtml(name)}</span>
            ${pathHtml}
          </span>`;
          const nameCell = url
            ? `<a class="sp-file-link" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${nameInner}</a>`
            : `<span class="sp-file-link sp-file-link--static">${nameInner}</span>`;
          const typeBadge = `<span class="sp-type-badge sp-type-badge--${escapeHtml(meta.tone)}">${escapeHtml(
            isFolder ? '📁 Folder' : meta.label
          )}</span>`;
          const person = String(item.person || '').trim();
          const modifiedBy = String(item.modified_by || '').trim();

          return `<tr class="sp-dialog-row${isFolder ? ' sp-dialog-row--folder' : ''}">
          <td>${nameCell}</td>
          <td>${typeBadge}</td>
          <td class="sp-meta-cell">${escapeHtml(formatModified(item.last_modified))}</td>
          <td class="sp-meta-cell">${modifiedBy ? `👤 ${escapeHtml(modifiedBy)}` : '—'}</td>
          <td class="sp-meta-cell">${person ? `🙋 ${escapeHtml(person)}` : '—'}</td>
        </tr>`;
        })
        .join('');
    };

    const openProject = async (projectName, sourceKey = '') => {
      const name = String(projectName || '').trim();
      if (!name) return;

      titleEl.textContent = name;
      subEl.innerHTML = '⏳ Loading SharePoint details…';
      actionsEl.innerHTML = '';
      rowsEl.innerHTML = '<tr><td colspan="5" class="sharepoint-dialog-empty">⏳ Loading…</td></tr>';
      dialog.showModal();

      try {
        const project = await fetchProjectDetail(name, sourceKey);
        const items = Array.isArray(project.items) ? project.items : [];
        const folders = items.filter((item) => item.item_type === 'folder').length;
        const files = items.length - folders;
        const catalogLabel = String(project.source_title || '').trim();
        subEl.innerHTML = `${
          catalogLabel ? `<span class="sp-catalog-badge">${escapeHtml(catalogLabel)}</span> · ` : ''
        }📦 <strong>${items.length}</strong> item${items.length === 1 ? '' : 's'} · 📁 <strong>${folders}</strong> folder${folders === 1 ? '' : 's'} · 📄 <strong>${files}</strong> file${files === 1 ? '' : 's'}`;

        if (project.folder_url) {
          actionsEl.innerHTML = `<a class="button button-primary btn-accent-violet-solid sp-open-folder-btn" href="${escapeHtml(project.folder_url)}" target="_blank" rel="noopener noreferrer">🔗 Open project folder in SharePoint</a>`;
        }

        renderRows(items);
      } catch (error) {
        subEl.textContent = '';
        rowsEl.innerHTML = `<tr><td colspan="5" class="sharepoint-dialog-empty">${escapeHtml(error.message || 'Failed to load project.')}</td></tr>`;
      }
    };

    closeBtn?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) dialog.close();
    });

    return openProject;
  };

  /* ---- Side-by-side compare dialog ---- */
  const initCompareDialog = () => {
    const dialog = document.getElementById('sharepoint-compare-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return null;

    const titleEl = document.getElementById('sharepoint-compare-dialog-title');
    const subEl = document.getElementById('sharepoint-compare-dialog-sub');
    const legendEl = document.getElementById('sharepoint-compare-legend');
    const closeBtn = document.getElementById('sharepoint-compare-dialog-close');

    const sideEls = {
      left: {
        title: document.getElementById('sharepoint-compare-left-title'),
        sub: document.getElementById('sharepoint-compare-left-sub'),
        actions: document.getElementById('sharepoint-compare-left-actions'),
        rows: document.getElementById('sharepoint-compare-left-rows'),
      },
      right: {
        title: document.getElementById('sharepoint-compare-right-title'),
        sub: document.getElementById('sharepoint-compare-right-sub'),
        actions: document.getElementById('sharepoint-compare-right-actions'),
        rows: document.getElementById('sharepoint-compare-right-rows'),
      },
    };

    const renderCompareRows = (items, otherKeys, side) => {
      const rowsEl = sideEls[side].rows;
      if (!Array.isArray(items) || items.length === 0) {
        rowsEl.innerHTML = '<tr><td colspan="3" class="sharepoint-dialog-empty">No files or folders.</td></tr>';
        return { both: 0, only: 0 };
      }

      let both = 0;
      let only = 0;
      rowsEl.innerHTML = items
        .map((item) => {
          const key = itemKey(item);
          const inOther = otherKeys.has(key);
          const diff = inOther ? 'both' : side === 'left' ? 'left' : 'right';
          if (inOther) both += 1;
          else only += 1;
          const name = String(item.name || '');
          const url = String(item.web_url || '');
          const path = String(item.relative_path || '');
          const meta = resolveMeta(item);
          const isFolder = String(item.item_type || '').toLowerCase() === 'folder';
          const pathHtml =
            path && path !== name
              ? `<span class="sharepoint-link-path">${escapeHtml(path)}</span>`
              : '';
          const nameInner = `
            <span class="sp-file-icon sp-file-icon--${escapeHtml(meta.tone)}" aria-hidden="true">${meta.emoji}</span>
            <span class="sp-file-copy">
              <span class="sp-file-name">${escapeHtml(name)}</span>
              ${pathHtml}
            </span>`;
          const nameCell = url
            ? `<a class="sp-file-link" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${nameInner}</a>`
            : `<span class="sp-file-link sp-file-link--static">${nameInner}</span>`;
          const typeBadge = `<span class="sp-type-badge sp-type-badge--${escapeHtml(meta.tone)}">${escapeHtml(
            isFolder ? '📁 Folder' : meta.label
          )}</span>`;
          const diffLabel =
            diff === 'both' ? 'In both' : diff === 'left' ? 'Only left' : 'Only right';
          return `<tr class="sp-dialog-row sp-compare-row sp-compare-row--${diff}${isFolder ? ' sp-dialog-row--folder' : ''}">
            <td>${nameCell}</td>
            <td>${typeBadge}</td>
            <td><span class="sp-diff-pill sp-diff-pill--${diff}">${diffLabel}</span></td>
          </tr>`;
        })
        .join('');

      return { both, only };
    };

    const fillSide = (side, project, stats) => {
      const els = sideEls[side];
      const catalog = String(project.source_title || project.source_key || '').trim();
      const items = Array.isArray(project.items) ? project.items : [];
      els.title.textContent = String(project.project_name || 'Project');
      els.sub.innerHTML = `${
        catalog ? `<span class="sp-catalog-badge">${escapeHtml(catalog)}</span> · ` : ''
      }${items.length} item${items.length === 1 ? '' : 's'} · ${stats.only} unique · ${stats.both} shared`;
      els.actions.innerHTML = project.folder_url
        ? `<a class="button ghost-light" href="${escapeHtml(project.folder_url)}" target="_blank" rel="noopener noreferrer">🔗 Open in SharePoint</a>`
        : '';
    };

    const openCompare = async (leftSel, rightSel) => {
      titleEl.textContent = 'Compare folders';
      subEl.textContent = 'Loading both catalogs…';
      legendEl.hidden = true;
      sideEls.left.title.textContent = leftSel.projectName;
      sideEls.right.title.textContent = rightSel.projectName;
      sideEls.left.sub.textContent = 'Loading…';
      sideEls.right.sub.textContent = 'Loading…';
      sideEls.left.actions.innerHTML = '';
      sideEls.right.actions.innerHTML = '';
      sideEls.left.rows.innerHTML = '<tr><td colspan="3" class="sharepoint-dialog-empty">⏳ Loading…</td></tr>';
      sideEls.right.rows.innerHTML = '<tr><td colspan="3" class="sharepoint-dialog-empty">⏳ Loading…</td></tr>';
      dialog.showModal();

      try {
        const [left, right] = await Promise.all([
          fetchProjectDetail(leftSel.projectName, leftSel.sourceKey),
          fetchProjectDetail(rightSel.projectName, rightSel.sourceKey),
        ]);
        const leftItems = Array.isArray(left.items) ? left.items : [];
        const rightItems = Array.isArray(right.items) ? right.items : [];
        const leftKeys = new Set(leftItems.map(itemKey));
        const rightKeys = new Set(rightItems.map(itemKey));
        const leftStats = renderCompareRows(leftItems, rightKeys, 'left');
        const rightStats = renderCompareRows(rightItems, leftKeys, 'right');
        fillSide('left', left, leftStats);
        fillSide('right', right, rightStats);

        const onlyLeft = leftStats.only;
        const onlyRight = rightStats.only;
        const shared = leftStats.both;
        titleEl.textContent =
          left.project_name === right.project_name
            ? String(left.project_name || 'Compare')
            : `${left.project_name} ↔ ${right.project_name}`;
        subEl.innerHTML = `<strong>${shared}</strong> in both · <strong>${onlyLeft}</strong> only left · <strong>${onlyRight}</strong> only right`;
        legendEl.hidden = false;
      } catch (error) {
        subEl.textContent = error.message || 'Compare failed.';
        sideEls.left.rows.innerHTML = `<tr><td colspan="3" class="sharepoint-dialog-empty">${escapeHtml(error.message || 'Failed')}</td></tr>`;
        sideEls.right.rows.innerHTML = `<tr><td colspan="3" class="sharepoint-dialog-empty">${escapeHtml(error.message || 'Failed')}</td></tr>`;
      }
    };

    closeBtn?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) dialog.close();
    });

    return openCompare;
  };

  const openProject = initDialog();
  const openCompare = initCompareDialog();

  /* ---- Live LinkNest-style search ---- */
  const searchRoot = document.getElementById('sharepoint-search');
  const tbody = document.getElementById('sharepoint-projects-tbody');
  if (!Fuzzy || !searchRoot || !tbody) {
    // Fallback: wire SSR rows to dialog only.
    document.querySelectorAll('.sharepoint-project-open').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openProject?.(
          btn.getAttribute('data-project-name') || '',
          btn.getAttribute('data-source-key') || ''
        );
      });
    });
    document.querySelectorAll('.sharepoint-project-row').forEach((row) => {
      row.addEventListener('click', (event) => {
        if (event.target.closest('a, button, input, label')) return;
        openProject?.(
          row.getAttribute('data-project-name') || '',
          row.getAttribute('data-source-key') || ''
        );
      });
      row.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openProject?.(
            row.getAttribute('data-project-name') || '',
            row.getAttribute('data-source-key') || ''
          );
        }
      });
    });
    return;
  }

  const STORAGE = {
    wordMode: 'riskregister_sp_search_word_mode',
    fuzzy: 'riskregister_sp_search_fuzzy',
    scopes: 'riskregister_sp_search_scopes',
  };

  const input = document.getElementById('sharepoint-search-input');
  const clearBtn = document.getElementById('sharepoint-search-clear');
  const controls = document.getElementById('sharepoint-search-controls');
  const wordModeGroup = document.getElementById('sharepoint-word-mode');
  const fuzzyToggle = document.getElementById('sharepoint-fuzzy-toggle');
  const refineInput = document.getElementById('sharepoint-refine-input');
  const refineClear = document.getElementById('sharepoint-refine-clear');
  const statsEl = document.getElementById('sharepoint-search-stats');
  const metaEl = document.getElementById('sharepoint-catalog-meta');
  const resultCountEl = document.getElementById('sharepoint-result-count');
  const paginationControls = document.getElementById('sharepoint-pagination-controls');
  const perPageSelect = document.getElementById('sharepoint-per-page');
  const form = document.getElementById('sharepoint-search-form');
  const headingEl = document.getElementById('sharepoint-search-heading');
  const scopesRoot = document.getElementById('sharepoint-search-scopes');
  const compareOpenBtn = document.getElementById('sharepoint-compare-open');
  const compareClearBtn = document.getElementById('sharepoint-compare-clear');
  const compareHintEl = document.getElementById('sharepoint-compare-hint');
  const MAX_COMPARE = 2;

  let availableSources = [];
  try {
    availableSources = JSON.parse(searchRoot.dataset.sources || '[]');
    if (!Array.isArray(availableSources)) availableSources = [];
  } catch {
    availableSources = [];
  }

  const titleByKey = Object.fromEntries(
    availableSources.map((src) => [String(src.source_key || ''), String(src.title || src.source_key || '')])
  );

  const readSavedScopes = () => {
    const urlSources = new URLSearchParams(window.location.search).get('sources');
    if (urlSources) {
      const fromUrl = urlSources
        .split(',')
        .map((key) => key.trim())
        .filter((key) => titleByKey[key]);
      if (fromUrl.length) return fromUrl;
    }
    try {
      const raw = JSON.parse(localStorage.getItem(STORAGE.scopes) || 'null');
      if (Array.isArray(raw) && raw.length) {
        const valid = raw.map(String).filter((key) => titleByKey[key]);
        if (valid.length) return valid;
      }
    } catch {
      /* ignore */
    }
    const checks = scopesRoot
      ? Array.from(scopesRoot.querySelectorAll('.sharepoint-scope-check:checked')).map((el) => el.value)
      : [];
    if (checks.length) return checks;
    return searchRoot.dataset.sourceKey ? [searchRoot.dataset.sourceKey] : [];
  };

  const state = {
    projects: [],
    sourceKey: searchRoot.dataset.sourceKey || '',
    scopeKeys: readSavedScopes(),
    selected: new Map(),
    itemCount: Number(searchRoot.dataset.itemCount || 0),
    projectCount: Number(searchRoot.dataset.projectCount || 0),
    lastSynced: searchRoot.dataset.lastSynced || '',
    lastStatus: searchRoot.dataset.lastStatus || '',
    query: searchRoot.dataset.initialQuery || '',
    refine: '',
    wordMode: localStorage.getItem(STORAGE.wordMode) === 'or' ? 'or' : 'and',
    fuzzy: localStorage.getItem(STORAGE.fuzzy) === '1',
    page: 1,
    perPage: Number(searchRoot.dataset.perPage || perPageSelect?.value || 25) || 25,
    ready: false,
    loadingIndex: false,
  };

  const projectFields = (project) => {
    const fields = [
      { text: project.project_name, sourceLabel: 'Project' },
      { text: project.source_title, sourceLabel: 'Catalog' },
      { text: project.modified_by, sourceLabel: 'Modified By' },
      { text: project.person, sourceLabel: 'Person' },
    ];
    (project.names || []).forEach((name) => {
      fields.push({ text: name, sourceLabel: 'File', sourceName: name });
    });
    (project.paths || []).forEach((path) => {
      fields.push({ text: path, sourceLabel: 'Path', sourceName: path });
    });
    return fields;
  };

  const scoreProject = (project, words, mode, fuzzy) =>
    Fuzzy.scoreLabeledFieldsAgainstWords(projectFields(project), words, mode, fuzzy);

  const scoreBadgeHtml = (match) => {
    if (!match?.matched) return '<span class="sp-match-placeholder">—</span>';
    const label = Fuzzy.matchKindLabel(match.kind);
    const reason = Fuzzy.formatMatchReason(match);
    const title = [
      `${label} match${match.token ? ` for “${match.token}”` : ''}`,
      `${match.score}% probability`,
      reason,
      match.snippet,
    ]
      .filter(Boolean)
      .join(' · ');
    const tone = match.score >= 90 ? 'high' : match.score >= 75 ? 'mid' : 'low';
    return `<span class="sp-match-badge-wrap" title="${escapeHtml(title)}">
      <span class="sp-match-badge sp-match-badge--${tone}">${match.score}% <span>${escapeHtml(label)}</span></span>
      ${reason ? `<span class="sp-match-reason">Matched: ${escapeHtml(reason)}${match.snippet ? `<span class="sp-match-snippet">“${escapeHtml(match.snippet)}”</span>` : ''}</span>` : ''}
    </span>`;
  };

  const presenceMap = () => {
    const map = new Map();
    state.projects.forEach((project) => {
      const name = String(project.project_name || '')
        .trim()
        .toLowerCase();
      if (!name) return;
      if (!map.has(name)) map.set(name, new Set());
      map.get(name).add(String(project.source_key || ''));
    });
    return map;
  };

  const coverageHtml = (project, presence) => {
    if (state.scopeKeys.length < 2) return '';
    const name = String(project.project_name || '')
      .trim()
      .toLowerCase();
    const present = presence.get(name) || new Set();
    const also = state.scopeKeys
      .filter((key) => key !== project.source_key && present.has(key))
      .map((key) => titleByKey[key] || key);
    const missing = state.scopeKeys
      .filter((key) => !present.has(key))
      .map((key) => titleByKey[key] || key);

    const parts = [];
    if (also.length) {
      parts.push(`<span class="sp-coverage sp-coverage--also">Also in ${escapeHtml(also.join(', '))}</span>`);
    }
    if (missing.length) {
      parts.push(`<span class="sp-coverage sp-coverage--missing">Not in ${escapeHtml(missing.join(', '))}</span>`);
    } else if (state.scopeKeys.length > 1) {
      parts.push('<span class="sp-coverage sp-coverage--all">In all selected catalogs</span>');
    }
    return parts.length ? `<div class="sp-coverage-line">${parts.join('')}</div>` : '';
  };

  const updateHeading = () => {
    if (!headingEl) return;
    if (state.scopeKeys.length <= 1) {
      const key = state.scopeKeys[0] || state.sourceKey;
      headingEl.textContent = `🔎 ${titleByKey[key] || searchRoot.dataset.sourceTitle || 'SharePoint catalog'}`;
      return;
    }
    if (state.scopeKeys.length === availableSources.length && availableSources.length > 1) {
      headingEl.textContent = `🔎 All catalogs (${state.scopeKeys.length})`;
      return;
    }
    headingEl.textContent = `🔎 ${state.scopeKeys.length} catalogs`;
  };

  const syncScopeChips = () => {
    if (!scopesRoot) return;
    scopesRoot.querySelectorAll('.sharepoint-scope-check').forEach((input) => {
      const checked = state.scopeKeys.includes(input.value);
      input.checked = checked;
      input.closest('.sharepoint-scope-chip')?.classList.toggle('is-active', checked);
    });
  };

  const syncCompareBar = () => {
    const count = state.selected.size;
    if (compareHintEl) {
      if (count === 0) {
        compareHintEl.textContent = 'Select 2 folders to compare side by side';
      } else if (count === 1) {
        compareHintEl.textContent = '1 selected — pick one more catalog folder';
      } else {
        compareHintEl.textContent = `${count} selected — ready to compare`;
      }
    }
    if (compareOpenBtn) {
      compareOpenBtn.disabled = count !== MAX_COMPARE;
      compareOpenBtn.textContent =
        count === MAX_COMPARE ? '⚖️ Compare selected' : `⚖️ Compare selected (${count}/${MAX_COMPARE})`;
    }
    if (compareClearBtn) {
      compareClearBtn.hidden = count === 0;
    }
  };

  const parseSelectionValue = (value) => {
    const raw = String(value || '');
    const sep = raw.indexOf('::');
    if (sep <= 0) return null;
    return {
      key: raw,
      sourceKey: raw.slice(0, sep),
      projectName: raw.slice(sep + 2),
    };
  };

  const toggleSelection = (value, checked) => {
    const parsed = parseSelectionValue(value);
    if (!parsed) return;
    if (checked) {
      if (state.selected.size >= MAX_COMPARE && !state.selected.has(parsed.key)) {
        // Drop oldest selection so the newest two stay selected.
        const firstKey = state.selected.keys().next().value;
        if (firstKey) state.selected.delete(firstKey);
      }
      state.selected.set(parsed.key, parsed);
    } else {
      state.selected.delete(parsed.key);
    }
    syncCompareBar();
    // Refresh checkbox checked state for rows that may have been auto-deselected.
    tbody.querySelectorAll('.sharepoint-compare-check').forEach((input) => {
      input.checked = state.selected.has(input.value);
      input.closest('.sharepoint-project-row')?.classList.toggle('is-compare-selected', input.checked);
    });
  };

  const bindRowEvents = () => {
    tbody.querySelectorAll('.sharepoint-project-open').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openProject?.(
          btn.getAttribute('data-project-name') || '',
          btn.getAttribute('data-source-key') || state.sourceKey
        );
      });
    });
    tbody.querySelectorAll('.sharepoint-compare-check').forEach((input) => {
      input.addEventListener('click', (event) => event.stopPropagation());
      input.addEventListener('change', () => {
        toggleSelection(input.value, input.checked);
      });
    });
    tbody.querySelectorAll('.sharepoint-project-row').forEach((row) => {
      row.addEventListener('click', (event) => {
        if (event.target.closest('a, button, input, label')) return;
        openProject?.(
          row.getAttribute('data-project-name') || '',
          row.getAttribute('data-source-key') || state.sourceKey
        );
      });
      row.addEventListener('keydown', (event) => {
        if (event.target.closest('input, label')) return;
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openProject?.(
            row.getAttribute('data-project-name') || '',
            row.getAttribute('data-source-key') || state.sourceKey
          );
        }
      });
    });
  };

  const filteredProjects = () => {
    const words = Fuzzy.getSearchWords(state.query);
    const refineWords = Fuzzy.getSearchWords(state.refine);

    if (!words.length) {
      return state.projects.map((project) => ({ project, match: null }));
    }

    let results = state.projects
      .map((project) => {
        const match = scoreProject(project, words, state.wordMode, state.fuzzy);
        return { project, match };
      })
      .filter((row) => row.match?.matched);

    if (refineWords.length) {
      results = results
        .map((row) => {
          const refineMatch = scoreProject(row.project, refineWords, state.wordMode, state.fuzzy);
          if (!refineMatch.matched) return null;
          return {
            project: row.project,
            match: Fuzzy.combineSearchScores(row.match, refineMatch),
          };
        })
        .filter(Boolean);
    }

    results.sort((a, b) => {
      const scoreDiff = (b.match?.score || 0) - (a.match?.score || 0);
      if (scoreDiff !== 0) return scoreDiff;
      const nameCmp = String(a.project.project_name || '').localeCompare(String(b.project.project_name || ''), undefined, {
        sensitivity: 'base',
      });
      if (nameCmp !== 0) return nameCmp;
      return String(a.project.source_title || '').localeCompare(String(b.project.source_title || ''), undefined, {
        sensitivity: 'base',
      });
    });

    return results;
  };

  const updateControlsVisibility = () => {
    controls.hidden = false;
    const words = Fuzzy.getSearchWords(state.query);
    const refineWords = Fuzzy.getSearchWords(state.refine);
    const multi = words.length > 1 || refineWords.length > 1;
    wordModeGroup.hidden = !multi;
    clearBtn.classList.toggle('is-hidden', state.query.trim() === '');
    refineClear.classList.toggle('is-hidden', state.refine.trim() === '');
    fuzzyToggle.classList.toggle('is-active', state.fuzzy);
    fuzzyToggle.setAttribute('aria-pressed', state.fuzzy ? 'true' : 'false');
    wordModeGroup.querySelectorAll('[data-word-mode]').forEach((btn) => {
      const active = btn.getAttribute('data-word-mode') === state.wordMode;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  };

  const renderStats = (rows) => {
    const searching = Fuzzy.getSearchWords(state.query).length > 0;
    if (!searching) {
      statsEl.classList.add('is-hidden');
      statsEl.innerHTML = '';
      return;
    }

    const scores = rows.map((row) => row.match?.score || 0);
    const exactCount = rows.filter((row) => row.match?.kind === 'exact').length;
    const similarCount = rows.length - exactCount;
    const avg = scores.length ? Math.round(scores.reduce((a, b) => a + b, 0) / scores.length) : 0;
    const best = scores.length ? Math.max(...scores) : 0;
    const barTone = avg >= 90 ? 'high' : avg >= 75 ? 'mid' : 'low';
    const catalogCount = new Set(rows.map((row) => row.project.source_key).filter(Boolean)).size;

    statsEl.classList.remove('is-hidden');
    statsEl.innerHTML = `
      <div class="sp-stats-row">
        <span class="sp-stats-title">Search stats</span>
        <span>${rows.length} project${rows.length === 1 ? '' : 's'}</span>
        ${state.scopeKeys.length > 1 ? `<span>${catalogCount} catalog${catalogCount === 1 ? '' : 's'} hit</span>` : ''}
        <span class="sp-stats-exact">${exactCount} exact</span>
        <span class="sp-stats-similar">${similarCount} similar</span>
        <span class="sp-stats-avg">Avg ${avg}%</span>
        <span>Best ${best}%</span>
        ${state.fuzzy ? '<span class="sp-stats-fuzzy">Fuzzy on</span>' : ''}
        ${state.refine.trim() ? `<span class="sp-stats-refine">Refined with “${escapeHtml(state.refine.trim())}”</span>` : ''}
      </div>
      <div class="sp-stats-bar-track">
        <div class="sp-stats-bar-rail">
          <div class="sp-stats-bar-fill sp-stats-bar-fill--${barTone}" style="width:${Math.max(avg, 4)}%"></div>
        </div>
        <span class="sp-stats-bar-label">${avg}% match probability</span>
      </div>`;
  };

  const renderMeta = (matchedCount) => {
    const searching = Fuzzy.getSearchWords(state.query).length > 0;
    let html = `${matchedCount} project${matchedCount === 1 ? '' : 's'}${searching ? ' matched' : ''}`;
    html += ` · ${state.itemCount} catalog item${state.itemCount === 1 ? '' : 's'} total`;
    if (state.scopeKeys.length > 1) {
      html += ` · Searching ${state.scopeKeys.length} catalogs`;
    }
    if (state.lastSynced && state.scopeKeys.length <= 1) {
      html += ` · Last update ${escapeHtml(state.lastSynced)}`;
      if (state.lastStatus) html += ` (${escapeHtml(state.lastStatus)})`;
    }
    if (state.ready) html += ' · <span class="sp-live-pill">⚡ Live search</span>';
    metaEl.innerHTML = html;
  };

  const renderPagination = (total) => {
    const totalPages = Math.max(1, Math.ceil(total / state.perPage));
    if (state.page > totalPages) state.page = totalPages;

    if (totalPages <= 1) {
      paginationControls.innerHTML = '';
      return;
    }

    const windowStart = Math.max(1, state.page - 2);
    const windowEnd = Math.min(totalPages, state.page + 2);
    let pages = '';
    for (let pageNum = windowStart; pageNum <= windowEnd; pageNum++) {
      if (pageNum === state.page) {
        pages += `<span class="pagination-page is-current" aria-current="page">${pageNum}</span>`;
      } else {
        pages += `<button type="button" class="pagination-page" data-page="${pageNum}">${pageNum}</button>`;
      }
    }

    paginationControls.innerHTML = `
      ${
        state.page > 1
          ? `<button type="button" class="button ghost" data-page="${state.page - 1}">← Previous</button>`
          : `<span class="button ghost is-disabled" aria-disabled="true">← Previous</span>`
      }
      <span class="pagination-pages">${pages}</span>
      ${
        state.page < totalPages
          ? `<button type="button" class="button ghost" data-page="${state.page + 1}">Next →</button>`
          : `<span class="button ghost is-disabled" aria-disabled="true">Next →</span>`
      }`;

    paginationControls.querySelectorAll('[data-page]').forEach((btn) => {
      btn.addEventListener('click', () => {
        state.page = Number(btn.getAttribute('data-page')) || 1;
        render();
        document.getElementById('sharepoint-table-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
  };

  const render = () => {
    updateControlsVisibility();
    updateHeading();
    syncScopeChips();
    const rows = filteredProjects();
    const total = rows.length;
    const from = total === 0 ? 0 : (state.page - 1) * state.perPage + 1;
    const to = Math.min(total, state.page * state.perPage);
    const pageRows = rows.slice((state.page - 1) * state.perPage, state.page * state.perPage);
    const searching = Fuzzy.getSearchWords(state.query).length > 0;
    const presence = presenceMap();

    resultCountEl.textContent = `Showing ${from}–${to} of ${total}`;
    renderMeta(total);
    renderStats(rows);
    renderPagination(total);

    if (pageRows.length === 0) {
      tbody.innerHTML = `<tr class="sharepoint-empty-row"><td colspan="8">${
        state.loadingIndex
          ? '⏳ Loading live search index…'
          : state.projects.length === 0
            ? '📁 No catalog items yet.'
            : searching
              ? `No projects matched <strong>${escapeHtml(state.query.trim())}</strong> in the selected catalog${state.scopeKeys.length === 1 ? '' : 's'}. Try Fuzzy, OR mode, or another name.`
              : 'No projects to show.'
      }</td></tr>`;
      syncCompareBar();
      return;
    }

    tbody.innerHTML = pageRows
      .map(({ project, match }) => {
        const name = String(project.project_name || '');
        const sourceKey = String(project.source_key || state.sourceKey || '');
        const sourceTitle = String(project.source_title || titleByKey[sourceKey] || sourceKey);
        const folderUrl = String(project.folder_url || '');
        const modifiedBy = String(project.modified_by || '').trim();
        const person = String(project.person || '').trim();
        const selectId = selectionKey(sourceKey, name);
        const isSelected = state.selected.has(selectId);
        return `<tr class="sharepoint-project-row${isSelected ? ' is-compare-selected' : ''}" data-project-name="${escapeHtml(name)}" data-source-key="${escapeHtml(sourceKey)}" tabindex="0">
          <td class="sharepoint-select-col" onclick="event.stopPropagation()">
            <label class="sharepoint-row-select">
              <input type="checkbox" class="sharepoint-compare-check" value="${escapeHtml(selectId)}" data-project-name="${escapeHtml(name)}" data-source-key="${escapeHtml(sourceKey)}" ${isSelected ? 'checked' : ''} aria-label="Select ${escapeHtml(name)} for compare">
            </label>
          </td>
          <td>
            <button type="button" class="sharepoint-project-open" data-project-name="${escapeHtml(name)}" data-source-key="${escapeHtml(sourceKey)}">
              <span class="sharepoint-project-open-icon" aria-hidden="true">📂</span>
              <span>${escapeHtml(name)}</span>
            </button>
            <div class="sp-project-meta-line">
              <span class="sp-catalog-badge">${escapeHtml(sourceTitle)}</span>
            </div>
            ${coverageHtml(project, presence)}
          </td>
          <td class="sp-match-cell">${searching ? scoreBadgeHtml(match) : '<span class="sp-match-placeholder">—</span>'}</td>
          <td>
            <span class="sharepoint-item-counts" title="${Number(project.folder_count || 0)} folders · ${Number(project.file_count || 0)} files">
              ${Number(project.item_count || 0)}
            </span>
          </td>
          <td>${escapeHtml(formatModified(project.last_modified))}</td>
          <td>${modifiedBy ? escapeHtml(modifiedBy) : '—'}</td>
          <td>${person ? escapeHtml(person) : '—'}</td>
          <td class="sharepoint-project-actions">
            ${
              folderUrl
                ? `<a class="button ghost-light sharepoint-open-sp" href="${escapeHtml(folderUrl)}" target="_blank" rel="noopener noreferrer" title="Open in SharePoint" onclick="event.stopPropagation()">🔗</a>`
                : ''
            }
          </td>
        </tr>`;
      })
      .join('');

    bindRowEvents();
    syncCompareBar();
  };

  const syncUrl = () => {
    const params = new URLSearchParams();
    if (state.sourceKey) params.set('source', state.sourceKey);
    if (state.scopeKeys.length > 1) {
      params.set('sources', state.scopeKeys.join(','));
    }
    if (state.query.trim()) params.set('q', state.query.trim());
    if (state.perPage !== 25) params.set('per', String(state.perPage));
    if (state.page > 1 && !state.query.trim()) params.set('page', String(state.page));
    const qs = params.toString();
    const next = `${window.location.pathname}${qs ? `?${qs}` : ''}#sharepoint-search`;
    window.history.replaceState(null, '', next);
  };

  const applySearch = ({ resetPage = true } = {}) => {
    if (resetPage) state.page = 1;
    if (input) input.value = state.query;
    if (refineInput) refineInput.value = state.refine;
    render();
    syncUrl();
  };

  const loadIndex = () => {
    const keys = state.scopeKeys.length ? state.scopeKeys : state.sourceKey ? [state.sourceKey] : [];
    if (!keys.length) return;
    state.loadingIndex = true;
    state.ready = false;
    tbody.innerHTML = '<tr class="sharepoint-empty-row"><td colspan="8">⏳ Loading live search index…</td></tr>';
    updateHeading();

    const qs =
      keys.length > 1 || (availableSources.length > 1 && keys.length === availableSources.length)
        ? `sources=${encodeURIComponent(keys.join(','))}`
        : `source=${encodeURIComponent(keys[0])}`;

    fetch(`sharepoint.php?action=search_index&${qs}`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then((response) => response.json())
      .then((payload) => {
        if (!payload?.ok || !Array.isArray(payload.projects)) {
          throw new Error(payload?.error || 'Unable to load search index.');
        }
        state.projects = payload.projects;
        state.itemCount = Number(payload.item_count || state.itemCount);
        state.projectCount = Number(payload.project_count || state.projects.length);
        state.lastSynced = payload.last_synced_at || state.lastSynced;
        state.lastStatus = payload.last_sync_status || state.lastStatus;
        state.loadingIndex = false;
        state.ready = true;
        applySearch({ resetPage: true });
      })
      .catch((error) => {
        state.loadingIndex = false;
        tbody.innerHTML = `<tr class="sharepoint-empty-row"><td colspan="8">${escapeHtml(error.message || 'Search index failed.')} Showing server results — refresh to retry live search.</td></tr>`;
        bindRowEvents();
      });
  };

  const setScopes = (keys) => {
    const next = [...new Set(keys.map(String).filter((key) => titleByKey[key] || key === state.sourceKey))];
    if (!next.length && state.sourceKey) next.push(state.sourceKey);
    if (!next.length) return;
    state.scopeKeys = next;
    localStorage.setItem(STORAGE.scopes, JSON.stringify(state.scopeKeys));
    loadIndex();
  };

  form?.addEventListener('submit', (event) => {
    event.preventDefault();
    state.query = input?.value || '';
    applySearch();
  });

  input?.addEventListener('input', () => {
    state.query = input.value;
    applySearch();
  });

  clearBtn?.addEventListener('click', () => {
    state.query = '';
    state.refine = '';
    applySearch();
    input?.focus();
  });

  refineInput?.addEventListener('input', () => {
    state.refine = refineInput.value;
    applySearch();
  });

  refineClear?.addEventListener('click', () => {
    state.refine = '';
    applySearch();
    refineInput?.focus();
  });

  fuzzyToggle?.addEventListener('click', () => {
    state.fuzzy = !state.fuzzy;
    localStorage.setItem(STORAGE.fuzzy, state.fuzzy ? '1' : '0');
    applySearch();
  });

  wordModeGroup?.querySelectorAll('[data-word-mode]').forEach((btn) => {
    btn.addEventListener('click', () => {
      state.wordMode = btn.getAttribute('data-word-mode') === 'or' ? 'or' : 'and';
      localStorage.setItem(STORAGE.wordMode, state.wordMode);
      applySearch();
    });
  });

  scopesRoot?.querySelectorAll('.sharepoint-scope-check').forEach((input) => {
    input.addEventListener('change', () => {
      const checked = Array.from(scopesRoot.querySelectorAll('.sharepoint-scope-check:checked')).map((el) => el.value);
      if (!checked.length) {
        input.checked = true;
        return;
      }
      setScopes(checked);
    });
  });

  document.getElementById('sharepoint-scopes-all')?.addEventListener('click', () => {
    setScopes(availableSources.map((src) => String(src.source_key || '')).filter(Boolean));
  });

  document.getElementById('sharepoint-scopes-active')?.addEventListener('click', () => {
    setScopes([state.sourceKey].filter(Boolean));
  });

  compareOpenBtn?.addEventListener('click', () => {
    const picks = [...state.selected.values()];
    if (picks.length !== MAX_COMPARE || !openCompare) return;
    openCompare(picks[0], picks[1]);
  });

  compareClearBtn?.addEventListener('click', () => {
    state.selected.clear();
    tbody.querySelectorAll('.sharepoint-compare-check').forEach((input) => {
      input.checked = false;
      input.closest('.sharepoint-project-row')?.classList.remove('is-compare-selected');
    });
    syncCompareBar();
  });

  perPageSelect?.addEventListener('change', () => {
    state.perPage = Number(perPageSelect.value) || 25;
    applySearch();
    const sourceQs = state.sourceKey ? `&source=${encodeURIComponent(state.sourceKey)}` : '';
    const saveUrl = `sharepoint.php?per=${encodeURIComponent(String(state.perPage))}${sourceQs}#sharepoint-search`;
    fetch(saveUrl, { credentials: 'same-origin' }).catch(() => {});
  });

  document.getElementById('sharepoint-per-page-form')?.addEventListener('submit', (event) => {
    event.preventDefault();
  });

  controls.hidden = false;
  syncScopeChips();
  syncCompareBar();
  loadIndex();
})();
