(() => {
  const root = document.getElementById('sharepoint-size-heatmap');
  const treemapEl = document.getElementById('sp-file-type-treemap');
  const treemapWrap = treemapEl?.closest('.sp-file-type-treemap-wrap');
  const kpisEl = document.getElementById('sp-file-type-kpis');
  const filesBody = document.getElementById('sp-file-type-files-body');
  const filesHelp = document.getElementById('sp-file-type-files-help');
  const filesTable = document.getElementById('sp-file-type-files-table');
  const scopesRoot = document.getElementById('sp-file-type-scopes');
  const extListEl = document.getElementById('sp-file-type-ext-list');
  const extCountEl = document.getElementById('sp-file-type-ext-count');
  const extSearch = document.getElementById('sp-file-type-ext-search');
  const extAllBtn = document.getElementById('sp-file-type-ext-all');
  const extClearBtn = document.getElementById('sp-file-type-ext-clear');
  const mapBtn = document.getElementById('sp-file-type-map-locations');
  const refreshBtn = document.getElementById('sp-file-type-refresh');
  const backBtn = document.getElementById('sp-file-type-back');
  const breadcrumbEl = document.getElementById('sp-file-type-breadcrumb');
  if (!root || !treemapEl) return;

  const SOURCES_KEY = 'riskregister_sp_size_heatmap_sources';
  const EXTS_KEY = 'riskregister_sp_file_type_extensions';
  const NAV_KEY = 'riskregister_sp_file_type_navigation';
  const MIN_TILE_PX = 44;

  const FILE_META = window.RiskRegisterSharePoint?.FILE_META || {
    pdf: { emoji: '📕', label: 'PDF' },
    doc: { emoji: '📘', label: 'Word' },
    docx: { emoji: '📘', label: 'Word' },
    xls: { emoji: '📊', label: 'Excel' },
    xlsx: { emoji: '📊', label: 'Excel' },
    xlsm: { emoji: '📊', label: 'Excel' },
    csv: { emoji: '📑', label: 'CSV' },
    ppt: { emoji: '📙', label: 'PowerPoint' },
    pptx: { emoji: '📙', label: 'PowerPoint' },
    vsd: { emoji: '📐', label: 'Visio' },
    vsdx: { emoji: '📐', label: 'Visio' },
    vsdm: { emoji: '📐', label: 'Visio' },
    vssx: { emoji: '📐', label: 'Visio' },
    vstx: { emoji: '📐', label: 'Visio' },
    jpg: { emoji: '🖼️', label: 'Image' },
    jpeg: { emoji: '🖼️', label: 'Image' },
    png: { emoji: '🖼️', label: 'Image' },
    gif: { emoji: '🖼️', label: 'Image' },
    zip: { emoji: '📦', label: 'Archive' },
    '7z': { emoji: '📦', label: 'Archive' },
    rar: { emoji: '📦', label: 'Archive' },
    msg: { emoji: '✉️', label: 'Email' },
    eml: { emoji: '✉️', label: 'Email' },
    mp4: { emoji: '🎬', label: 'Video' },
    txt: { emoji: '📝', label: 'Text' },
    dwg: { emoji: '🏗️', label: 'CAD' },
    dxf: { emoji: '🏗️', label: 'CAD' },
  };

  const state = {
    loaded: false,
    loading: false,
    data: null,
    typesPayload: null,
    level: 'types',
    sourceKey: '',
    sourceTitle: '',
    projectName: '',
    folderPath: '',
    selectedExts: new Set(),
    availableTypes: [],
    extSearch: '',
    largeFiles: [],
    fileSort: { key: 'size', direction: 'desc' },
  };
  let heatmapAnimationTimer = 0;

  const escapeHtml = (value) =>
    String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

  const escapeAttr = (value) => escapeHtml(value).replace(/\r?\n/g, '&#10;');

  const formatBytes = (value) => {
    const n = Number(value) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    if (n < 1024 * 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} MB`;
    return `${(n / (1024 * 1024 * 1024)).toFixed(2)} GB`;
  };

  const formatCount = (value) => Number(value || 0).toLocaleString();

  const formatModified = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return '—';
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(raw);
    if (match) {
      const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
      return `${months[Number(match[2]) - 1] || match[2]} ${Number(match[3])}, ${match[1]}`;
    }
    return raw;
  };

  const nodeColor = (hue, alpha = 0.82) => `hsla(${Number(hue) || 200}, 58%, 42%, ${alpha})`;

  const fileExtension = (name) => {
    const base = String(name || '').split(/[\\/]/).pop() || '';
    const dot = base.lastIndexOf('.');
    if (dot <= 0 || dot === base.length - 1) return '';
    return base.slice(dot + 1).toLowerCase();
  };

  const extMeta = (ext) => {
    const key = String(ext || '').toLowerCase();
    const meta = FILE_META[key];
    if (meta) return meta;
    return { emoji: '📄', label: key ? key.toUpperCase() : 'File' };
  };

  const apiUrl = (params = {}) => {
    const qs = new URLSearchParams();
    qs.set('action', 'file_type_stats');
    Object.entries(params).forEach(([key, value]) => {
      if (value === undefined || value === null || String(value) === '') return;
      qs.set(key, String(value));
    });
    return `sharepoint.php?${qs.toString()}`;
  };

  const readSelectedSources = () => {
    const checks = root.querySelectorAll('.sp-file-type-scope-check:checked');
    const keys = Array.from(checks).map((el) => el.value).filter(Boolean);
    if (keys.length) return keys;
    const fromSize = Array.from(root.querySelectorAll('.sp-size-scope-check:checked'))
      .map((el) => el.value)
      .filter(Boolean);
    if (fromSize.length) return fromSize;
    const first = root.querySelector('.sp-file-type-scope-check');
    return first?.value ? [first.value] : [];
  };

  const saveSelectedSources = (keys) => {
    try {
      localStorage.setItem(SOURCES_KEY, JSON.stringify(keys));
    } catch (e) { /* storage may be unavailable */ }
  };

  const saveSelectedExts = () => {
    try {
      localStorage.setItem(EXTS_KEY, JSON.stringify(Array.from(state.selectedExts)));
    } catch (e) { /* ignore */ }
  };

  const readStoredExts = () => {
    try {
      const parsed = JSON.parse(localStorage.getItem(EXTS_KEY) || 'null');
      if (Array.isArray(parsed)) {
        return parsed.map((v) => String(v).toLowerCase().replace(/^\./, '')).filter(Boolean);
      }
    } catch (e) { /* ignore */ }
    return [];
  };

  const saveNavigation = () => {
    try {
      localStorage.setItem(
        NAV_KEY,
        JSON.stringify({
          level: state.level,
          sourceKey: String(state.sourceKey || '').slice(0, 200),
          sourceTitle: String(state.sourceTitle || '').slice(0, 200),
          projectName: String(state.projectName || '').slice(0, 300),
          folderPath: String(state.folderPath || '').slice(0, 800),
        })
      );
    } catch (e) { /* ignore */ }
  };

  const syncScopesFromStorage = () => {
    const checks = Array.from(root.querySelectorAll('.sp-file-type-scope-check'));
    if (!checks.length) return;
    let stored = null;
    try {
      const parsed = JSON.parse(localStorage.getItem(SOURCES_KEY) || 'null');
      if (Array.isArray(parsed)) stored = new Set(parsed.map(String));
    } catch (e) { /* ignore */ }
    if (stored) {
      checks.forEach((input) => {
        input.checked = stored.has(input.value);
      });
      if (!checks.some((input) => input.checked)) {
        checks[0].checked = true;
      }
    }
    updateScopesUi();
  };

  const updateScopesUi = () => {
    const checks = Array.from(root.querySelectorAll('.sp-file-type-scope-check'));
    if (!checks.length) return;
    const selected = checks.filter((el) => el.checked).length;
    const countEl = document.getElementById('sp-file-type-scopes-count');
    if (countEl) countEl.textContent = `${selected} of ${checks.length}`;
    const allBtn = document.getElementById('sp-file-type-scopes-all');
    if (allBtn) {
      const allSelected = selected === checks.length;
      allBtn.disabled = allSelected;
      allBtn.classList.toggle('is-active', allSelected);
      allBtn.textContent = allSelected ? 'All selected' : 'Select all';
    }
    checks.forEach((input) => {
      input.closest('.sharepoint-scope-chip')?.classList.toggle('is-active', input.checked);
    });
  };

  const syncOtherScopeChecks = (keys) => {
    const selected = new Set(keys);
    root
      .querySelectorAll('.sp-size-scope-check, .sp-duplicates-scope-check, .sp-owner-storage-scope-check')
      .forEach((input) => {
        input.checked = selected.has(input.value);
        input.closest('.sharepoint-scope-chip')?.classList.toggle('is-active', input.checked);
      });
  };

  const updateExtSelectionUi = () => {
    const total = state.availableTypes.length;
    const selected = state.selectedExts.size;
    if (extCountEl) {
      extCountEl.textContent = `${selected} of ${total}`;
    }
    if (extAllBtn) {
      const allSelected = total > 0 && selected === total;
      extAllBtn.disabled = allSelected || total === 0;
      extAllBtn.classList.toggle('is-active', allSelected);
      extAllBtn.textContent = allSelected ? 'All selected' : 'Select all';
    }
    if (extClearBtn) {
      extClearBtn.hidden = selected === 0;
    }
    if (mapBtn) {
      mapBtn.disabled = selected === 0;
    }
  };

  const renderExtChips = () => {
    if (!extListEl) return;
    const q = String(state.extSearch || '').trim().toLowerCase().replace(/^\./, '');
    const types = state.availableTypes.filter((t) => {
      if (!q) return true;
      const ext = String(t.ext || '');
      const meta = extMeta(ext);
      return (
        ext.includes(q) ||
        String(meta.label || '')
          .toLowerCase()
          .includes(q)
      );
    });
    if (!types.length) {
      extListEl.innerHTML = `<p class="panel-help">${
        state.availableTypes.length ? 'No file types match this filter.' : 'No files with extensions in selected catalogs.'
      }</p>`;
      updateExtSelectionUi();
      return;
    }
    extListEl.innerHTML = types
      .map((t) => {
        const ext = String(t.ext || '');
        const meta = extMeta(ext);
        const count = Number(t.file_count) || 0;
        const active = state.selectedExts.has(ext);
        return `<label class="sharepoint-scope-chip sp-file-type-ext-chip${active ? ' is-active' : ''}" data-ext="${escapeAttr(ext)}" title="${escapeAttr(
          `${meta.label} · .${ext} · ${formatCount(count)} files · ${formatBytes(t.size_bytes)}`
        )}">
          <input type="checkbox" class="sp-file-type-ext-check" value="${escapeAttr(ext)}" ${active ? 'checked' : ''}>
          <span class="sp-file-type-ext-emoji" aria-hidden="true">${meta.emoji || '📄'}</span>
          <span class="sp-file-type-ext-label">${escapeHtml(meta.label)}</span>
          <code class="sp-file-type-ext-code">.${escapeHtml(ext)}</code>
          <b class="sp-file-type-ext-badge">${escapeHtml(formatCount(count))}</b>
        </label>`;
      })
      .join('');
    updateExtSelectionUi();
  };

  const getTreemapSize = () => {
    const wrapWidth = Math.floor((treemapWrap?.clientWidth || treemapEl.clientWidth || 640) - 16);
    const width = Math.max(280, wrapWidth);
    const size = document.documentElement.getAttribute('data-size') || 'l';
    const settings = {
      s: { ratio: 0.3, min: 240, max: 320, viewport: 0.45 },
      m: { ratio: 0.34, min: 280, max: 400, viewport: 0.52 },
      l: { ratio: 0.4, min: 340, max: 520, viewport: 0.62 },
      xl: { ratio: 0.44, min: 420, max: 680, viewport: 0.7 },
      xxl: { ratio: 0.48, min: 500, max: 850, viewport: 0.76 },
    };
    const config = settings[size] || settings.l;
    const viewportLimit = Math.max(config.min, Math.floor(window.innerHeight * config.viewport));
    const height = Math.min(config.max, viewportLimit, Math.max(config.min, Math.round(width * config.ratio)));
    return { width, height };
  };

  const maxTreemapTiles = () => {
    const size = document.documentElement.getAttribute('data-size') || 'l';
    return { s: 24, m: 36, l: 48, xl: 72, xxl: 100 }[size] || 48;
  };

  const prepareNodes = (nodes) => {
    const sized = (nodes || [])
      .filter((node) => (Number(node.size_bytes) || 0) > 0)
      .slice()
      .sort((a, b) => (Number(b.size_bytes) || 0) - (Number(a.size_bytes) || 0));
    const tileLimit = maxTreemapTiles();
    if (sized.length <= tileLimit) return sized;
    const top = sized.slice(0, tileLimit - 1);
    const rest = sized.slice(tileLimit - 1);
    const otherBytes = rest.reduce((sum, node) => sum + (Number(node.size_bytes) || 0), 0);
    const otherFiles = rest.reduce((sum, node) => sum + (Number(node.file_count) || 0), 0);
    top.push({
      key: '__other__',
      label: `Other (${rest.length})`,
      type: 'other',
      size_bytes: otherBytes,
      file_count: otherFiles,
      hue: 215,
    });
    return top;
  };

  const squarify = (nodes, x, y, width, height) => {
    const prepared = prepareNodes(nodes);
    const total = prepared.reduce((sum, node) => sum + (Number(node.size_bytes) || 0), 0);
    const items = prepared.map((node) => ({
      node,
      area: ((Number(node.size_bytes) || 0) / total) * (width * height),
    }));
    if (!items.length || width <= 0 || height <= 0 || total <= 0) return [];

    const rects = [];
    let row = [];
    let rx = x;
    let ry = y;
    let rw = width;
    let rh = height;

    const rowSum = () => row.reduce((sum, item) => sum + item.area, 0);
    const worst = (rowItems, side) => {
      if (!rowItems.length) return Infinity;
      const s = rowItems.reduce((acc, item) => acc + item.area, 0);
      let max = 0;
      let min = Infinity;
      rowItems.forEach((item) => {
        max = Math.max(max, item.area);
        min = Math.min(min, item.area);
      });
      const sideSq = side * side;
      return Math.max((sideSq * max) / (s * s), (s * s) / (sideSq * min));
    };

    const layoutRow = (horizontal) => {
      const s = rowSum();
      if (horizontal) {
        const rowHeight = s / rw;
        let cx = rx;
        row.forEach((item) => {
          const cw = item.area / rowHeight;
          rects.push({ node: item.node, x: cx, y: ry, width: cw, height: rowHeight });
          cx += cw;
        });
        ry += rowHeight;
        rh -= rowHeight;
      } else {
        const rowWidth = s / rh;
        let cy = ry;
        row.forEach((item) => {
          const ch = item.area / rowWidth;
          rects.push({ node: item.node, x: rx, y: cy, width: rowWidth, height: ch });
          cy += ch;
        });
        rx += rowWidth;
        rw -= rowWidth;
      }
      row = [];
    };

    items.forEach((item) => {
      const next = row.concat([item]);
      const horizontal = rw < rh;
      const side = Math.min(rw, rh);
      if (!row.length || worst(next, side) <= worst(row, side)) {
        row = next;
      } else {
        layoutRow(horizontal);
        row = [item];
      }
    });
    if (row.length) layoutRow(rw < rh);
    return rects;
  };

  const replayHeatmapAnimation = () => {
    const tiles = Array.from(treemapEl.querySelectorAll('.sp-size-tile'));
    tiles.forEach((tile) => {
      tile.classList.remove('is-settling');
      tile.style.removeProperty('--sp-settle-delay');
    });
    window.clearTimeout(heatmapAnimationTimer);
    if (!tiles.length || root.dataset.listAnimation === 'none') return;
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches) return;

    void treemapEl.offsetWidth;
    tiles.forEach((tile, index) => {
      tile.classList.add('is-settling');
      tile.style.setProperty('--sp-settle-delay', `${Math.min(index * 14, 240)}ms`);
    });

    const rawDuration = getComputedStyle(root)
      .getPropertyValue('--sp-user-animation-duration')
      .trim();
    const duration = rawDuration.endsWith('ms')
      ? Number.parseFloat(rawDuration)
      : Number.parseFloat(rawDuration) * 1000;
    heatmapAnimationTimer = window.setTimeout(() => {
      tiles.forEach((tile) => {
        tile.classList.remove('is-settling');
        tile.style.removeProperty('--sp-settle-delay');
      });
    }, (Number.isFinite(duration) ? duration : 580) + 300);
  };

  const selectedExtLabel = () => {
    const exts = Array.from(state.selectedExts);
    if (!exts.length) return 'selected types';
    if (exts.length === 1) {
      const meta = extMeta(exts[0]);
      return `${meta.label} (.${exts[0]})`;
    }
    if (exts.length <= 3) return exts.map((e) => `.${e}`).join(', ');
    return `${exts.length} file types`;
  };

  const renderTreemap = (nodes) => {
    const { width, height } = getTreemapSize();
    treemapEl.style.width = '100%';
    treemapEl.style.height = `${height}px`;
    treemapEl.style.maxHeight = `${height}px`;

    const rects = squarify(nodes, 0, 0, width, height);
    if (!rects.length) {
      treemapEl.innerHTML = `<p class="sp-size-empty">${
        state.level === 'types'
          ? 'No file-type storage data for this view.'
          : 'No matching files for the selected types.'
      }</p>`;
      return;
    }

    treemapEl.innerHTML = rects
      .map(({ node, x, y, width: w, height: h }) => {
        const type = String(node.type || 'catalog');
        const ext = String(node.ext || node.key || '');
        const meta = type === 'file-type' ? extMeta(ext) : null;
        const label =
          type === 'file-type'
            ? `${meta?.emoji || '📄'} ${meta?.label || ext.toUpperCase()}`
            : String(node.label || node.key || '');
        const bytes = Number(node.size_bytes) || 0;
        const files = Number(node.file_count) || 0;
        const showLabel = w >= MIN_TILE_PX && h >= MIN_TILE_PX;
        const tip = [
          type === 'file-type' ? `.${ext} · ${meta?.label || ''}` : label,
          formatBytes(bytes),
          files ? `${files} file(s)` : '',
          type === 'file-type' ? 'Click to map where these files live' : '',
          type === 'catalog' || type === 'project' || type === 'folder' ? 'Click to drill down' : '',
          type === 'file' ? 'Click to open file' : '',
        ]
          .filter(Boolean)
          .join('\n');
        const drillable = ['file-type', 'catalog', 'project', 'folder', 'file'].includes(type) && type !== 'other';
        const left = Math.max(0, Math.min(x, width - 1));
        const top = Math.max(0, Math.min(y, height - 1));
        const tileW = Math.max(1, Math.min(w, width - left));
        const tileH = Math.max(1, Math.min(h, height - top));
        return `<button type="button" class="sp-size-tile sp-file-type-tile${drillable ? ' is-drillable' : ''}${
          type === 'other' ? ' is-other' : ''
        }${type === 'file' ? ' is-file' : ''}"
          style="left:${left}px;top:${top}px;width:${tileW}px;height:${tileH}px;background:${nodeColor(node.hue)}"
          data-type="${escapeAttr(type)}"
          data-ext="${escapeAttr(ext)}"
          data-key="${escapeAttr(node.key || '')}"
          data-label="${escapeAttr(node.label || label)}"
          data-source-key="${escapeAttr(node.source_key || '')}"
          data-path="${escapeAttr(node.path || '')}"
          data-web-url="${escapeAttr(node.web_url || '')}"
          title="${escapeAttr(tip)}"
          aria-label="${escapeAttr(`${label}, ${formatBytes(bytes)}`)}"
        >${
          showLabel
            ? `<span class="sp-size-tile-label">${escapeHtml(label)}</span><span class="sp-size-tile-size">${escapeHtml(
                formatBytes(bytes)
              )}</span>`
            : `<span class="sp-size-tile-dot" aria-hidden="true"></span>`
        }</button>`;
      })
      .join('');
    replayHeatmapAnimation();
  };

  const renderBreadcrumb = () => {
    if (!breadcrumbEl) return;
    const crumbs = [];
    crumbs.push(
      `<button type="button" class="sp-size-crumb${state.level === 'types' ? ' is-active' : ''}" data-ft-level="types"${
        state.level === 'types' ? ' aria-current="location"' : ''
      }>All file types</button>`
    );
    if (state.level !== 'types') {
      crumbs.push(`<span class="sp-size-crumb-sep" aria-hidden="true">›</span>`);
      crumbs.push(
        `<button type="button" class="sp-size-crumb${state.level === 'overview' ? ' is-active' : ''}" data-ft-level="overview"${
          state.level === 'overview' ? ' aria-current="location"' : ''
        }>${escapeHtml(selectedExtLabel())}</button>`
      );
    }
    if (state.level === 'projects' || state.level === 'folder') {
      crumbs.push(`<span class="sp-size-crumb-sep" aria-hidden="true">›</span>`);
      crumbs.push(
        `<button type="button" class="sp-size-crumb${state.level === 'projects' ? ' is-active' : ''}" data-ft-level="projects" data-source-key="${escapeAttr(
          state.sourceKey
        )}"${state.level === 'projects' ? ' aria-current="location"' : ''}>${escapeHtml(
          state.sourceTitle || state.sourceKey
        )}</button>`
      );
    }
    if (state.level === 'folder' && state.projectName) {
      const path = state.folderPath || state.projectName;
      const parts = path.split('/').filter(Boolean);
      let built = '';
      parts.forEach((part, index) => {
        built = built ? `${built}/${part}` : part;
        const isLast = index === parts.length - 1;
        crumbs.push(`<span class="sp-size-crumb-sep" aria-hidden="true">›</span>`);
        crumbs.push(
          `<button type="button" class="sp-size-crumb${isLast ? ' is-active' : ''}" data-ft-level="folder" data-source-key="${escapeAttr(
            state.sourceKey
          )}" data-project-name="${escapeAttr(state.projectName)}" data-path="${escapeAttr(built)}"${
            isLast ? ' aria-current="location"' : ''
          }>${escapeHtml(part)}</button>`
        );
      });
    }
    breadcrumbEl.innerHTML = crumbs.join('');
    if (backBtn) backBtn.hidden = state.level === 'types';
    if (scopesRoot) scopesRoot.hidden = state.level !== 'types' && state.level !== 'overview';
    const extScopes = document.getElementById('sp-file-type-ext-scopes');
    if (extScopes) extScopes.hidden = state.level !== 'types';
  };

  const renderKpis = (kpis) => {
    if (!kpisEl) return;
    if (!kpis) {
      kpisEl.innerHTML = '';
      return;
    }
    let cards;
    if (state.level === 'types') {
      cards = [
        { label: 'Total storage', value: formatBytes(kpis.total_bytes) },
        { label: 'File types', value: formatCount(kpis.type_count) },
        { label: 'Files', value: formatCount(kpis.file_count) },
        {
          label: 'Largest type',
          value: kpis.largest_label || '—',
          hint: kpis.largest_label ? formatBytes(kpis.largest_bytes) : '',
        },
      ];
    } else if (state.level === 'overview') {
      cards = [
        { label: 'Filtered storage', value: formatBytes(kpis.total_bytes) },
        { label: 'Catalogs', value: formatCount(kpis.catalog_count) },
        { label: 'Files', value: formatCount(kpis.file_count) },
        {
          label: 'Largest project',
          value: kpis.largest_label || '—',
          hint: kpis.largest_label ? formatBytes(kpis.largest_bytes) : '',
        },
      ];
    } else {
      cards = [
        { label: 'Storage', value: formatBytes(kpis.total_bytes) },
        { label: 'Files', value: formatCount(kpis.file_count) },
        {
          label: state.level === 'projects' ? 'Projects' : 'Items',
          value: formatCount(kpis.project_count ?? kpis.item_count),
        },
      ];
    }
    kpisEl.innerHTML = cards
      .map(
        (card) => `<div class="sp-size-kpi">
          <span class="sp-size-kpi-label">${escapeHtml(card.label)}</span>
          <strong class="sp-size-kpi-value">${escapeHtml(card.value)}</strong>
          ${card.hint ? `<span class="sp-size-kpi-hint">${escapeHtml(card.hint)}</span>` : ''}
        </div>`
      )
      .join('');
  };

  const compareFiles = (a, b, key) => {
    if (key === 'size') return (Number(a.size_bytes) || 0) - (Number(b.size_bytes) || 0);
    if (key === 'modified') {
      return String(a.last_modified || '').localeCompare(String(b.last_modified || ''));
    }
    if (key === 'ext') {
      return fileExtension(a.name).localeCompare(fileExtension(b.name));
    }
    const left = key === 'project' ? a.project_name : a.name;
    const right = key === 'project' ? b.project_name : b.name;
    return String(left || '').localeCompare(String(right || ''), undefined, {
      numeric: true,
      sensitivity: 'base',
    });
  };

  const syncFileSortHeaders = () => {
    filesTable?.querySelectorAll('th[data-ft-file-sort]').forEach((header) => {
      const active = header.getAttribute('data-ft-file-sort') === state.fileSort.key;
      const direction = active ? state.fileSort.direction : 'none';
      header.setAttribute(
        'aria-sort',
        direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : 'none'
      );
      header.classList.toggle('is-sorted-asc', active && direction === 'asc');
      header.classList.toggle('is-sorted-desc', active && direction === 'desc');
    });
  };

  const renderLargeFiles = (files) => {
    if (!filesBody) return;
    state.largeFiles = Array.isArray(files) ? files.slice() : [];
    const multiplier = state.fileSort.direction === 'asc' ? 1 : -1;
    const rows = state.largeFiles
      .slice()
      .sort((a, b) => compareFiles(a, b, state.fileSort.key) * multiplier);
    syncFileSortHeaders();
    if (filesHelp) {
      filesHelp.textContent = rows.length
        ? state.level === 'types'
          ? `Top ${rows.length} largest files across selected catalogs`
          : `Top ${rows.length} largest files for ${selectedExtLabel()}`
        : 'No files in this view';
    }
    if (!rows.length) {
      filesBody.innerHTML = '<tr><td colspan="5" class="sp-size-empty">No files found.</td></tr>';
      return;
    }
    filesBody.innerHTML = rows
      .map((file) => {
        const url = String(file.web_url || '');
        const nameCell = url
          ? `<a href="${escapeAttr(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(file.name || '')}</a>`
          : escapeHtml(file.name || '');
        const ext = fileExtension(file.name);
        const meta = extMeta(ext);
        return `<tr>
          <td class="sp-size-file-name">${nameCell}</td>
          <td><span class="sp-file-type-file-ext">${escapeHtml(meta.emoji || '')} .${escapeHtml(ext || '—')}</span></td>
          <td>${escapeHtml(file.project_name || '')}</td>
          <td class="sp-size-file-bytes">${escapeHtml(formatBytes(file.size_bytes))}</td>
          <td>${escapeHtml(formatModified(file.last_modified))}</td>
        </tr>`;
      })
      .join('');
  };

  const applyTypesPayload = (payload) => {
    state.typesPayload = payload;
    state.availableTypes = Array.isArray(payload.types) ? payload.types.slice() : [];
    const available = new Set(state.availableTypes.map((t) => String(t.ext || '')));
    const stored = readStoredExts().filter((ext) => available.has(ext));
    if (!state.selectedExts.size && stored.length) {
      state.selectedExts = new Set(stored);
    } else {
      Array.from(state.selectedExts).forEach((ext) => {
        if (!available.has(ext)) state.selectedExts.delete(ext);
      });
    }
    renderExtChips();
  };

  const applyPayload = (payload) => {
    state.data = payload;
    state.level = String(payload.level || state.level || 'types');
    if (state.level === 'projects') {
      state.sourceKey = String(payload.source_key || state.sourceKey || '');
      state.sourceTitle = String(payload.source_title || state.sourceTitle || state.sourceKey);
    }
    if (state.level === 'folder') {
      state.sourceKey = String(payload.source_key || state.sourceKey || '');
      state.projectName = String(payload.project_name || state.projectName || '');
      state.folderPath = String(payload.folder_path || state.folderPath || state.projectName);
    }
    if (state.level === 'types') {
      state.sourceKey = '';
      state.sourceTitle = '';
      state.projectName = '';
      state.folderPath = '';
      applyTypesPayload(payload);
    }
    saveNavigation();
    renderBreadcrumb();
    renderKpis(payload.kpis || {});
    renderTreemap(payload.nodes || []);
    renderLargeFiles(payload.large_files || []);
  };

  const load = async (force = false) => {
    if (state.loading) return;
    if (state.loaded && !force && state.data?.nodes && state.level === 'types') {
      renderTreemap(state.data.nodes || []);
      renderExtChips();
      return;
    }
    state.loading = true;
    treemapEl.innerHTML = '<p class="sp-size-empty">Loading…</p>';
    if (filesBody) {
      filesBody.innerHTML = '<tr><td colspan="5" class="sp-size-empty">Loading…</td></tr>';
    }
    try {
      const sources = readSelectedSources();
      const params = { sources: sources.join(',') || 'all', level: state.level };
      if (state.level !== 'types') {
        const exts = Array.from(state.selectedExts);
        if (!exts.length) {
          throw new Error('Select at least one file type to map locations.');
        }
        params.extensions = exts.join(',');
      }
      if (state.level === 'projects' || state.level === 'folder') {
        params.source = state.sourceKey;
      }
      if (state.level === 'folder') {
        params.project = state.projectName;
        params.path = state.folderPath || state.projectName;
      }
      const response = await fetch(apiUrl(params), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || 'Failed to load file-type storage data.');
      }
      applyPayload(payload);
      state.loaded = true;
    } catch (error) {
      treemapEl.innerHTML = `<p class="sp-size-empty sp-size-error">${escapeHtml(error.message || 'Load failed.')}</p>`;
      if (filesBody) {
        filesBody.innerHTML = `<tr><td colspan="5" class="sp-size-empty sp-size-error">${escapeHtml(
          error.message || 'Load failed.'
        )}</td></tr>`;
      }
      if (kpisEl) kpisEl.innerHTML = '';
    } finally {
      state.loading = false;
    }
  };

  const showTypes = () => {
    state.level = 'types';
    state.sourceKey = '';
    state.sourceTitle = '';
    state.projectName = '';
    state.folderPath = '';
    state.loaded = false;
    saveNavigation();
    load(true);
  };

  const mapLocations = (exts = null) => {
    if (Array.isArray(exts) && exts.length) {
      state.selectedExts = new Set(exts.map((e) => String(e).toLowerCase().replace(/^\./, '')).filter(Boolean));
      saveSelectedExts();
      renderExtChips();
    }
    if (!state.selectedExts.size) return;
    state.level = 'overview';
    state.sourceKey = '';
    state.sourceTitle = '';
    state.projectName = '';
    state.folderPath = '';
    state.loaded = false;
    saveNavigation();
    load(true);
  };

  const drillCatalog = (sourceKey, sourceTitle = '') => {
    if (!sourceKey) return;
    state.level = 'projects';
    state.sourceKey = sourceKey;
    state.sourceTitle = sourceTitle || sourceKey;
    state.projectName = '';
    state.folderPath = '';
    state.loaded = false;
    saveNavigation();
    load(true);
  };

  const drillProject = (sourceKey, projectName) => {
    if (!sourceKey || !projectName) return;
    state.level = 'folder';
    state.sourceKey = sourceKey;
    state.projectName = projectName;
    state.folderPath = projectName;
    state.loaded = false;
    saveNavigation();
    load(true);
  };

  const drillFolder = (sourceKey, projectName, path) => {
    if (!sourceKey || !projectName) return;
    state.level = 'folder';
    state.sourceKey = sourceKey;
    state.projectName = projectName;
    state.folderPath = path || projectName;
    state.loaded = false;
    saveNavigation();
    load(true);
  };

  const goBack = () => {
    if (state.level === 'folder') {
      const path = state.folderPath || state.projectName;
      if (path && path !== state.projectName && path.includes('/')) {
        const parent = path.split('/').slice(0, -1).join('/');
        drillFolder(state.sourceKey, state.projectName, parent || state.projectName);
        return;
      }
      drillCatalog(state.sourceKey, state.sourceTitle);
      return;
    }
    if (state.level === 'projects') {
      mapLocations();
      return;
    }
    if (state.level === 'overview') {
      showTypes();
    }
  };

  treemapEl.addEventListener('click', (event) => {
    const tile = event.target.closest('.sp-file-type-tile');
    if (!tile) return;
    const type = tile.getAttribute('data-type') || '';
    if (type === 'file-type') {
      const ext = tile.getAttribute('data-ext') || '';
      if (ext) mapLocations([ext]);
      return;
    }
    if (type === 'catalog') {
      drillCatalog(tile.getAttribute('data-key') || tile.getAttribute('data-source-key') || '', tile.getAttribute('data-label') || '');
      return;
    }
    if (type === 'project') {
      drillProject(
        tile.getAttribute('data-source-key') || state.sourceKey,
        tile.getAttribute('data-key') || tile.getAttribute('data-label') || ''
      );
      return;
    }
    if (type === 'folder') {
      drillFolder(
        tile.getAttribute('data-source-key') || state.sourceKey,
        state.projectName,
        tile.getAttribute('data-path') || tile.getAttribute('data-key') || ''
      );
      return;
    }
    if (type === 'file') {
      const url = tile.getAttribute('data-web-url') || '';
      if (url) window.open(url, '_blank', 'noopener,noreferrer');
    }
  });

  breadcrumbEl?.addEventListener('click', (event) => {
    const crumb = event.target.closest('[data-ft-level]');
    if (!crumb || crumb.classList.contains('is-active')) return;
    const level = crumb.getAttribute('data-ft-level');
    if (level === 'types') {
      showTypes();
      return;
    }
    if (level === 'overview') {
      mapLocations();
      return;
    }
    if (level === 'projects') {
      drillCatalog(crumb.getAttribute('data-source-key') || state.sourceKey, state.sourceTitle);
      return;
    }
    if (level === 'folder') {
      drillFolder(
        crumb.getAttribute('data-source-key') || state.sourceKey,
        crumb.getAttribute('data-project-name') || state.projectName,
        crumb.getAttribute('data-path') || state.projectName
      );
    }
  });

  backBtn?.addEventListener('click', goBack);
  refreshBtn?.addEventListener('click', () => load(true));
  mapBtn?.addEventListener('click', () => mapLocations());

  filesTable?.querySelector('thead')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-ft-file-sort-button]');
    if (!button) return;
    const key = button.getAttribute('data-ft-file-sort-button') || 'name';
    if (state.fileSort.key === key) {
      state.fileSort.direction = state.fileSort.direction === 'asc' ? 'desc' : 'asc';
    } else {
      state.fileSort.key = key;
      state.fileSort.direction = key === 'size' || key === 'modified' ? 'desc' : 'asc';
    }
    renderLargeFiles(state.largeFiles);
  });

  scopesRoot?.addEventListener('change', (event) => {
    if (!event.target.classList.contains('sp-file-type-scope-check')) return;
    const checks = root.querySelectorAll('.sp-file-type-scope-check:checked');
    if (!checks.length) {
      event.target.checked = true;
      return;
    }
    const keys = readSelectedSources();
    saveSelectedSources(keys);
    syncOtherScopeChecks(keys);
    updateScopesUi();
    showTypes();
  });

  document.getElementById('sp-file-type-scopes-all')?.addEventListener('click', () => {
    root.querySelectorAll('.sp-file-type-scope-check').forEach((el) => {
      el.checked = true;
    });
    const keys = readSelectedSources();
    saveSelectedSources(keys);
    syncOtherScopeChecks(keys);
    updateScopesUi();
    showTypes();
  });

  extListEl?.addEventListener('change', (event) => {
    const input = event.target.closest('.sp-file-type-ext-check');
    if (!input) return;
    const ext = String(input.value || '').toLowerCase();
    if (!ext) return;
    if (input.checked) state.selectedExts.add(ext);
    else state.selectedExts.delete(ext);
    saveSelectedExts();
    input.closest('.sp-file-type-ext-chip')?.classList.toggle('is-active', input.checked);
    updateExtSelectionUi();
  });

  extAllBtn?.addEventListener('click', () => {
    state.availableTypes.forEach((t) => {
      if (t.ext) state.selectedExts.add(String(t.ext));
    });
    saveSelectedExts();
    renderExtChips();
  });

  extClearBtn?.addEventListener('click', () => {
    state.selectedExts.clear();
    saveSelectedExts();
    renderExtChips();
  });

  let searchTimer = 0;
  extSearch?.addEventListener('input', () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
      state.extSearch = extSearch.value || '';
      renderExtChips();
    }, 120);
  });

  let resizeTimer = 0;
  const scheduleTreemapResize = () => {
    if (!state.loaded || !state.data?.nodes) return;
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(() => renderTreemap(state.data.nodes || []), 120);
  };
  window.addEventListener('resize', scheduleTreemapResize);
  if (typeof ResizeObserver === 'function' && treemapWrap) {
    const resizeObserver = new ResizeObserver(scheduleTreemapResize);
    resizeObserver.observe(treemapWrap);
  }

  syncScopesFromStorage();
  renderBreadcrumb();
  updateExtSelectionUi();

  window.RiskRegisterFileTypeStorage = {
    load: (force = false) => {
      if (force) state.loaded = false;
      return load(force);
    },
    isLoaded: () => state.loaded,
    invalidate: () => {
      state.loaded = false;
      state.data = null;
      state.typesPayload = null;
      state.level = 'types';
      state.sourceKey = '';
      state.sourceTitle = '';
      state.projectName = '';
      state.folderPath = '';
      saveNavigation();
    },
    redraw: () => {
      if (state.loaded && state.data?.nodes) renderTreemap(state.data.nodes || []);
    },
    syncScopesFromStorage,
  };
})();
