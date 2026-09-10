(() => {
  const root = document.getElementById('sharepoint-size-heatmap');
  const shell = document.getElementById('sharepoint-size-heatmap-shell');
  const treemapEl = document.getElementById('sp-size-treemap');
  const kpisEl = document.getElementById('sp-size-kpis');
  const breadcrumbEl = document.getElementById('sp-size-breadcrumb');
  const filesTable = document.getElementById('sp-size-files-table');
  const filesBody = document.getElementById('sp-size-files-body');
  const largeHelp = document.getElementById('sp-size-large-help');
  const backBtn = document.getElementById('sp-size-back');
  const refreshBtn = document.getElementById('sp-size-refresh');
  const scopesRoot = document.getElementById('sp-size-scopes');
  const helperBody = document.getElementById('sp-size-heatmap-body');
  if (!root || !shell || !treemapEl) return;

  const isSolo = root.getAttribute('data-solo') === '1';
  const SHELL_KEY = 'riskregister_sp_size_heatmap_open';
  const SOURCES_KEY = 'riskregister_sp_size_heatmap_sources';
  const NAV_KEY = 'riskregister_sp_size_heatmap_navigation';
  const MIN_TILE_PX = 44;
  const treemapWrap = treemapEl.closest('.sp-size-treemap-wrap');

  const state = {
    loaded: false,
    loading: false,
    data: null,
    level: 'overview',
    sourceKey: '',
    sourceTitle: '',
    projectName: '',
    folderPath: '',
    selectedSources: [],
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

  const apiUrl = (params = {}) => {
    const base = 'sharepoint.php';
    const qs = new URLSearchParams();
    qs.set('action', 'size_stats');
    Object.entries(params).forEach(([key, value]) => {
      if (value === undefined || value === null || String(value) === '') return;
      qs.set(key, String(value));
    });
    return `${base}?${qs.toString()}`;
  };

  const readSelectedSources = () => {
    const checks = root.querySelectorAll('.sp-size-scope-check:checked');
    const keys = Array.from(checks).map((el) => el.value).filter(Boolean);
    if (keys.length) return keys;
    const first = root.querySelector('.sp-size-scope-check');
    return first?.value ? [first.value] : [];
  };

  const saveSelectedSources = () => {
    try {
      localStorage.setItem(SOURCES_KEY, JSON.stringify(readSelectedSources()));
    } catch (e) { /* storage may be unavailable */ }
  };

  const restoreSelectedSources = () => {
    const checks = Array.from(root.querySelectorAll('.sp-size-scope-check'));
    if (!checks.length) return;
    let stored = null;
    try {
      const parsed = JSON.parse(localStorage.getItem(SOURCES_KEY) || 'null');
      if (Array.isArray(parsed)) stored = new Set(parsed.map(String));
    } catch (e) { /* ignore invalid stored data */ }
    if (!stored) return;
    checks.forEach((input) => {
      input.checked = stored.has(input.value);
    });
    if (!checks.some((input) => input.checked)) {
      checks[0].checked = true;
    }
  };

  const sourceTitleFor = (sourceKey) => {
    try {
      const sources = JSON.parse(root.getAttribute('data-sources') || '[]');
      const source = Array.isArray(sources)
        ? sources.find((item) => String(item.source_key || '') === sourceKey)
        : null;
      return String(source?.title || sourceKey);
    } catch (e) {
      return sourceKey;
    }
  };

  const savedNavigation = () => {
    try {
      const value = JSON.parse(localStorage.getItem(NAV_KEY) || 'null');
      if (!value || typeof value !== 'object') return null;
      const level = String(value.level || '');
      const sourceKey = String(value.sourceKey || '').slice(0, 255);
      const projectName = String(value.projectName || '').slice(0, 500);
      const folderPath = String(value.folderPath || '').slice(0, 1200);
      if (level === 'projects' && sourceKey) {
        return { level, sourceKey, projectName: '', folderPath: '' };
      }
      if (level === 'folder' && sourceKey && projectName) {
        return {
          level,
          sourceKey,
          projectName,
          folderPath: folderPath || projectName,
        };
      }
    } catch (e) { /* ignore unavailable or invalid storage */ }
    return null;
  };

  const saveNavigation = () => {
    try {
      localStorage.setItem(NAV_KEY, JSON.stringify({
        level: state.level,
        sourceKey: state.sourceKey,
        projectName: state.projectName,
        folderPath: state.folderPath,
      }));
    } catch (e) { /* storage may be unavailable */ }
  };

  const updateScopesUi = () => {
    const checks = root.querySelectorAll('.sp-size-scope-check');
    const checked = root.querySelectorAll('.sp-size-scope-check:checked');
    const countEl = document.getElementById('sp-size-scopes-count');
    const allBtn = document.getElementById('sp-size-scopes-all');
    if (countEl) countEl.textContent = `${checked.length} of ${checks.length}`;
    if (allBtn) {
      const allOn = checked.length === checks.length;
      allBtn.textContent = allOn ? 'All selected' : 'Select all';
      allBtn.classList.toggle('is-active', allOn);
      allBtn.disabled = allOn;
    }
    scopesRoot?.querySelectorAll('.sharepoint-scope-chip').forEach((chip) => {
      const input = chip.querySelector('.sp-size-scope-check');
      chip.classList.toggle('is-active', !!input?.checked);
    });
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
    if (sized.length <= tileLimit) {
      return sized;
    }
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
    const pixelArea = width * height;
    const items = prepared.map((node) => ({
      node,
      area: ((Number(node.size_bytes) || 0) / total) * pixelArea,
    }));
    if (!items.length || width <= 0 || height <= 0) return [];
    if (total <= 0) return [];

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

  window.RiskRegisterSharePoint = Object.assign(window.RiskRegisterSharePoint || {}, {
    replayHeatmapAnimation,
  });

  const renderTreemap = (nodes) => {
    const { width, height } = getTreemapSize();
    treemapEl.style.width = '100%';
    treemapEl.style.height = `${height}px`;
    treemapEl.style.maxHeight = `${height}px`;

    const rects = squarify(nodes, 0, 0, width, height);
    if (!rects.length) {
      treemapEl.innerHTML = '<p class="sp-size-empty">No storage data for this view.</p>';
      return;
    }

    treemapEl.innerHTML = rects
      .map(({ node, x, y, width: w, height: h }) => {
        const label = String(node.label || node.key || '');
        const bytes = Number(node.size_bytes) || 0;
        const type = String(node.type || '');
        const showLabel = w >= MIN_TILE_PX && h >= MIN_TILE_PX;
        const tip = `${label}\n${formatBytes(bytes)}${node.file_count ? `\n${node.file_count} file(s)` : ''}`;
        const drillable = type === 'catalog' || type === 'project' || type === 'folder';
        const left = Math.max(0, Math.min(x, width - 1));
        const top = Math.max(0, Math.min(y, height - 1));
        const tileW = Math.max(1, Math.min(w, width - left));
        const tileH = Math.max(1, Math.min(h, height - top));
        return `<button type="button" class="sp-size-tile${drillable ? ' is-drillable' : ''}${type === 'file' ? ' is-file' : ''}${type === 'other' ? ' is-other' : ''}"
          style="left:${left}px;top:${top}px;width:${tileW}px;height:${tileH}px;background:${nodeColor(node.hue)}"
          data-type="${escapeAttr(type)}"
          data-key="${escapeAttr(node.key || '')}"
          data-label="${escapeAttr(label)}"
          data-source-key="${escapeAttr(node.source_key || state.sourceKey || '')}"
          data-path="${escapeAttr(node.path || '')}"
          data-web-url="${escapeAttr(node.web_url || '')}"
          title="${escapeAttr(tip)}"
          aria-label="${escapeAttr(`${label}, ${formatBytes(bytes)}`)}"
          ${drillable ? '' : ' tabindex="-1"'}
        >${showLabel ? `<span class="sp-size-tile-label">${escapeHtml(label)}</span><span class="sp-size-tile-size">${escapeHtml(formatBytes(bytes))}</span>` : `<span class="sp-size-tile-dot" aria-hidden="true"></span>`}</button>`;
      })
      .join('');
    replayHeatmapAnimation();
  };

  const renderKpis = (kpis, level) => {
    if (!kpisEl || !kpis) {
      kpisEl.innerHTML = '';
      return;
    }
    const cards = [];
    cards.push({ label: 'Total storage', value: formatBytes(kpis.total_bytes) });
    if (level === 'overview') {
      cards.push({ label: 'Catalogs', value: String(kpis.catalog_count ?? 0) });
      cards.push({ label: 'Projects', value: String(kpis.project_count ?? 0) });
    } else if (level === 'projects') {
      cards.push({ label: 'Projects', value: String(kpis.project_count ?? 0) });
    } else {
      cards.push({ label: 'Items', value: String(kpis.item_count ?? 0) });
    }
    cards.push({ label: 'Files', value: String(kpis.file_count ?? 0) });
    if (kpis.largest_bytes > 0 && level === 'overview') {
      cards.push({ label: 'Largest project', value: formatBytes(kpis.largest_bytes) });
    }
    kpisEl.innerHTML = cards
      .map(
        (card) =>
          `<div class="sp-size-kpi"><span class="sp-size-kpi-label">${escapeHtml(card.label)}</span><strong class="sp-size-kpi-value">${escapeHtml(card.value)}</strong></div>`
      )
      .join('');
  };

  const compareFiles = (a, b, key) => {
    if (key === 'size') {
      return (Number(a.size_bytes) || 0) - (Number(b.size_bytes) || 0);
    }
    if (key === 'modified') {
      return String(a.last_modified || '').localeCompare(String(b.last_modified || ''));
    }
    const left = key === 'project' ? a.project_name : a.name;
    const right = key === 'project' ? b.project_name : b.name;
    return String(left || '').localeCompare(String(right || ''), undefined, {
      numeric: true,
      sensitivity: 'base',
    });
  };

  const syncFileSortHeaders = () => {
    filesTable?.querySelectorAll('th[data-file-sort]').forEach((header) => {
      const active = header.getAttribute('data-file-sort') === state.fileSort.key;
      const direction = active ? state.fileSort.direction : 'none';
      header.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : 'none');
      header.classList.toggle('is-sorted-asc', active && direction === 'asc');
      header.classList.toggle('is-sorted-desc', active && direction === 'desc');
    });
  };

  const renderLargeFiles = (files, level) => {
    if (!filesBody) return;
    state.largeFiles = Array.isArray(files) ? files.slice() : [];
    const multiplier = state.fileSort.direction === 'asc' ? 1 : -1;
    const rows = state.largeFiles
      .slice()
      .sort((a, b) => compareFiles(a, b, state.fileSort.key) * multiplier);
    syncFileSortHeaders();
    if (largeHelp) {
      const scope =
        level === 'folder'
          ? 'in this folder'
          : level === 'projects'
            ? 'in this catalog'
            : 'across selected catalogs';
      largeHelp.textContent = rows.length ? `Top ${rows.length} largest files ${scope}` : `No files ${scope}`;
    }
    if (!rows.length) {
      filesBody.innerHTML = '<tr><td colspan="4" class="sp-size-empty">No files found.</td></tr>';
      return;
    }
    filesBody.innerHTML = rows
      .map((file) => {
        const url = String(file.web_url || '');
        const nameCell = url
          ? `<a href="${escapeAttr(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(file.name || '')}</a>`
          : escapeHtml(file.name || '');
        return `<tr>
          <td class="sp-size-file-name">${nameCell}</td>
          <td>${escapeHtml(file.project_name || '')}</td>
          <td class="sp-size-file-bytes">${escapeHtml(formatBytes(file.size_bytes))}</td>
          <td>${escapeHtml(formatModified(file.last_modified))}</td>
        </tr>`;
      })
      .join('');
  };

  const renderBreadcrumb = () => {
    if (!breadcrumbEl) return;
    const crumbs = [{ level: 'overview', label: 'All catalogs' }];
    if (state.level === 'projects' || state.level === 'folder') {
      crumbs.push({
        level: 'projects',
        label: state.sourceTitle || state.sourceKey,
        source: state.sourceKey,
      });
    }
    if (state.level === 'folder') {
      let trail = state.data?.breadcrumb || [];
      if (!trail.length && state.projectName) {
        const segments = String(state.folderPath || state.projectName)
          .split('/')
          .filter(Boolean);
        let path = '';
        trail = segments.map((label) => {
          path = path ? `${path}/${label}` : label;
          return { label, path };
        });
      }
      trail.forEach((crumb, index) => {
        if (index === 0) return;
        crumbs.push({
          level: 'folder',
          label: crumb.label,
          path: crumb.path,
          source: state.sourceKey,
          project: state.projectName,
        });
      });
    }
    breadcrumbEl.innerHTML = crumbs
      .map((crumb, index) => {
        const isLast = index === crumbs.length - 1;
        return `<button type="button" class="sp-size-crumb${isLast ? ' is-active' : ''}"
          data-level="${escapeAttr(crumb.level)}"
          data-source="${escapeAttr(crumb.source || '')}"
          data-project="${escapeAttr(crumb.project || state.projectName || '')}"
          data-path="${escapeAttr(crumb.path || '')}"
          ${isLast ? ' aria-current="location"' : ''}
        >${escapeHtml(crumb.label)}</button>`;
      })
      .join('<span class="sp-size-crumb-sep" aria-hidden="true">›</span>');
    if (backBtn) backBtn.hidden = state.level === 'overview';
    if (scopesRoot) scopesRoot.hidden = state.level !== 'overview';
  };

  const applyPayload = (payload) => {
    state.data = payload;
    state.level = payload.level || 'overview';
    if (payload.source_key) state.sourceKey = payload.source_key;
    if (payload.source_title) state.sourceTitle = payload.source_title;
    if (payload.project_name) state.projectName = payload.project_name;
    if (payload.folder_path) state.folderPath = payload.folder_path;

    renderBreadcrumb();
    renderKpis(payload.kpis || {}, state.level);
    renderTreemap(payload.nodes || []);
    renderLargeFiles(payload.large_files || [], state.level);
    saveNavigation();
    if (helperBody) helperBody.hidden = true;
  };

  const fetchView = async (params) => {
    state.loading = true;
    treemapEl.innerHTML = '<p class="sp-size-empty">Loading…</p>';
    if (filesBody) filesBody.innerHTML = '<tr><td colspan="4" class="sp-size-empty">Loading…</td></tr>';
    try {
      const response = await fetch(apiUrl(params), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || 'Failed to load storage data.');
      }
      applyPayload(payload);
      state.loaded = true;
    } catch (error) {
      treemapEl.innerHTML = `<p class="sp-size-empty sp-size-error">${escapeHtml(error.message || 'Load failed.')}</p>`;
      if (filesBody) {
        filesBody.innerHTML = `<tr><td colspan="4" class="sp-size-empty sp-size-error">${escapeHtml(error.message || 'Load failed.')}</td></tr>`;
      }
    } finally {
      state.loading = false;
    }
  };

  const loadOverview = () => {
    state.data = null;
    state.level = 'overview';
    state.sourceKey = '';
    state.sourceTitle = '';
    state.projectName = '';
    state.folderPath = '';
    state.selectedSources = readSelectedSources();
    renderBreadcrumb();
    return fetchView({
      level: 'overview',
      sources: state.selectedSources.join(','),
    });
  };

  const loadProjects = (sourceKey, sourceTitle) => {
    state.data = null;
    state.level = 'projects';
    state.sourceKey = sourceKey;
    state.sourceTitle = sourceTitle || sourceKey;
    state.projectName = '';
    state.folderPath = '';
    renderBreadcrumb();
    return fetchView({ level: 'projects', source: sourceKey });
  };

  const loadFolder = (sourceKey, projectName, folderPath) => {
    state.data = null;
    state.level = 'folder';
    state.sourceKey = sourceKey;
    state.projectName = projectName;
    state.folderPath = folderPath || projectName;
    renderBreadcrumb();
    return fetchView({
      level: 'folder',
      source: sourceKey,
      project: projectName,
      path: folderPath || projectName,
    });
  };

  const loadInitialView = () => {
    const saved = savedNavigation();
    if (!saved) return loadOverview();
    state.sourceTitle = sourceTitleFor(saved.sourceKey);
    if (saved.level === 'projects') {
      return loadProjects(saved.sourceKey, state.sourceTitle);
    }
    return loadFolder(saved.sourceKey, saved.projectName, saved.folderPath);
  };

  const drillIntoNode = (node) => {
    const type = String(node.type || '');
    if (type === 'catalog') {
      loadProjects(node.key, node.label);
      return;
    }
    if (type === 'project') {
      loadFolder(node.source_key || state.sourceKey, node.key, node.key);
      return;
    }
    if (type === 'folder') {
      loadFolder(state.sourceKey, state.projectName, node.path || node.key);
      return;
    }
    const url = String(node.web_url || '');
    if (url) window.open(url, '_blank', 'noopener,noreferrer');
  };

  const goBack = () => {
    if (state.level === 'folder') {
      const trail = state.data?.breadcrumb || [];
      if (trail.length > 2) {
        const parent = trail[trail.length - 2];
        loadFolder(state.sourceKey, state.projectName, parent.path);
        return;
      }
      loadProjects(state.sourceKey, state.sourceTitle);
      return;
    }
    if (state.level === 'projects') {
      loadOverview();
    }
  };

  treemapEl.addEventListener('click', (event) => {
    const tile = event.target.closest('.sp-size-tile');
    if (!tile) return;
    const type = tile.getAttribute('data-type') || '';
    drillIntoNode({
      type,
      key: tile.getAttribute('data-key') || '',
      label: tile.getAttribute('data-label') || '',
      source_key: tile.getAttribute('data-source-key') || '',
      path: tile.getAttribute('data-path') || '',
      web_url: tile.getAttribute('data-web-url') || '',
      hue: 200,
    });
  });

  breadcrumbEl?.addEventListener('click', (event) => {
    const crumb = event.target.closest('.sp-size-crumb');
    if (!crumb || crumb.classList.contains('is-active')) return;
    const level = crumb.getAttribute('data-level') || 'overview';
    if (level === 'overview') {
      loadOverview();
      return;
    }
    if (level === 'projects') {
      loadProjects(crumb.getAttribute('data-source') || state.sourceKey, crumb.textContent?.trim() || '');
      return;
    }
    loadFolder(
      crumb.getAttribute('data-source') || state.sourceKey,
      crumb.getAttribute('data-project') || state.projectName,
      crumb.getAttribute('data-path') || ''
    );
  });

  backBtn?.addEventListener('click', goBack);
  refreshBtn?.addEventListener('click', () => {
    if (state.level === 'overview') loadOverview();
    else if (state.level === 'projects') loadProjects(state.sourceKey, state.sourceTitle);
    else loadFolder(state.sourceKey, state.projectName, state.folderPath);
  });

  filesTable?.querySelector('thead')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-file-sort-button]');
    if (!button) return;
    const key = button.getAttribute('data-file-sort-button') || 'name';
    if (state.fileSort.key === key) {
      state.fileSort.direction = state.fileSort.direction === 'asc' ? 'desc' : 'asc';
    } else {
      state.fileSort.key = key;
      state.fileSort.direction = key === 'size' || key === 'modified' ? 'desc' : 'asc';
    }
    renderLargeFiles(state.largeFiles, state.level);
  });

  scopesRoot?.addEventListener('change', (event) => {
    if (!event.target.classList.contains('sp-size-scope-check')) return;
    const checks = root.querySelectorAll('.sp-size-scope-check:checked');
    if (!checks.length) {
      event.target.checked = true;
      return;
    }
    saveSelectedSources();
    updateScopesUi();
    if (state.level === 'overview') loadOverview();
  });

  document.getElementById('sp-size-scopes-all')?.addEventListener('click', () => {
    root.querySelectorAll('.sp-size-scope-check').forEach((el) => {
      el.checked = true;
    });
    saveSelectedSources();
    updateScopesUi();
    if (state.level === 'overview') loadOverview();
  });

  document.getElementById('sp-size-open-tab')?.addEventListener('click', (event) => {
    event.preventDefault();
    window.open('sharepoint.php?view=heatmap', '_blank', 'noopener,noreferrer');
  });

  document.getElementById('sp-size-open-window')?.addEventListener('click', () => {
    const width = Math.min(1280, screen.availWidth - 40);
    const height = Math.min(900, screen.availHeight - 40);
    const left = Math.max(0, Math.round((screen.availWidth - width) / 2));
    const top = Math.max(0, Math.round((screen.availHeight - height) / 2));
    window.open(
      'sharepoint.php?view=heatmap',
      'riskregister_sp_size_heatmap',
      `noopener,noreferrer,width=${width},height=${height},left=${left},top=${top}`
    );
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
  if (typeof MutationObserver === 'function') {
    const sizeObserver = new MutationObserver((mutations) => {
      if (mutations.some((mutation) => mutation.attributeName === 'data-size')) {
        scheduleTreemapResize();
      }
    });
    sizeObserver.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['data-size'],
    });
  }

  const maybeLoad = () => {
    if (state.loaded || state.loading) return;
    if (!isSolo && !shell.open) return;
    loadInitialView();
  };

  restoreSelectedSources();
  updateScopesUi();

  if (isSolo) {
    try {
      localStorage.setItem(SHELL_KEY, '1');
    } catch (e) { /* ignore */ }
    maybeLoad();
  } else {
    try {
      if (localStorage.getItem(SHELL_KEY) === '1') shell.open = true;
    } catch (e) { /* ignore */ }
    shell.addEventListener('toggle', () => {
      try {
        localStorage.setItem(SHELL_KEY, shell.open ? '1' : '0');
      } catch (e) { /* ignore */ }
      maybeLoad();
    });
    if (shell.open) maybeLoad();
  }

})();
