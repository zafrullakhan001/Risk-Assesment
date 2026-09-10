(() => {
  const root = document.getElementById('sharepoint-size-heatmap');
  const treemapEl = document.getElementById('sp-owner-storage-treemap');
  const treemapWrap = treemapEl?.closest('.sp-owner-storage-treemap-wrap');
  const kpisEl = document.getElementById('sp-owner-storage-kpis');
  const qualityEl = document.getElementById('sp-owner-storage-quality');
  const filesBody = document.getElementById('sp-owner-storage-files-body');
  const filesHelp = document.getElementById('sp-owner-storage-files-help');
  const filesTable = document.getElementById('sp-owner-storage-files-table');
  const scopesRoot = document.getElementById('sp-owner-storage-scopes');
  const refreshBtn = document.getElementById('sp-owner-storage-refresh');
  const backBtn = document.getElementById('sp-owner-storage-back');
  const breadcrumbEl = document.getElementById('sp-owner-storage-breadcrumb');
  const ownerDashLink = document.getElementById('sp-owner-storage-to-owners');
  if (!root || !treemapEl) return;

  const SOURCES_KEY = 'riskregister_sp_size_heatmap_sources';
  const OWNER_NAV_KEY = 'riskregister_sp_owner_storage_navigation';
  const MIN_TILE_PX = 44;

  const state = {
    loaded: false,
    loading: false,
    data: null,
    level: 'overview',
    ownerKey: '',
    ownerName: '',
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

  const formatPct = (value) => `${Math.round((Number(value) || 0) * 100)}%`;

  const formatCreated = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return '—';
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(raw);
    if (match) {
      const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
      return `${months[Number(match[2]) - 1] || match[2]} ${Number(match[3])}, ${match[1]}`;
    }
    return raw;
  };

  const createdSortKey = (value) => {
    const raw = String(value || '').trim();
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(raw);
    if (match) return `${match[1]}-${match[2]}-${match[3]}`;
    // Fall back so non-ISO SharePoint dates still sort somewhat consistently.
    return raw;
  };

  const nodeColor = (hue, alpha = 0.82) => `hsla(${Number(hue) || 200}, 58%, 42%, ${alpha})`;

  const apiUrl = (params = {}) => {
    const qs = new URLSearchParams();
    qs.set('action', 'owner_storage_stats');
    Object.entries(params).forEach(([key, value]) => {
      if (value === undefined || value === null || String(value) === '') return;
      qs.set(key, String(value));
    });
    return `sharepoint.php?${qs.toString()}`;
  };

  const readSelectedSources = () => {
    const checks = root.querySelectorAll('.sp-owner-storage-scope-check:checked');
    const keys = Array.from(checks).map((el) => el.value).filter(Boolean);
    if (keys.length) return keys;
    const fromSize = Array.from(root.querySelectorAll('.sp-size-scope-check:checked')).map((el) => el.value).filter(Boolean);
    if (fromSize.length) return fromSize;
    const first = root.querySelector('.sp-owner-storage-scope-check');
    return first?.value ? [first.value] : [];
  };

  const saveOwnerNavigation = () => {
    try {
      if (state.level === 'owner' && state.ownerKey) {
        localStorage.setItem(
          OWNER_NAV_KEY,
          JSON.stringify({
            level: 'owner',
            ownerKey: String(state.ownerKey).slice(0, 300),
            ownerName: String(state.ownerName || '').slice(0, 300),
          })
        );
      } else {
        localStorage.setItem(OWNER_NAV_KEY, JSON.stringify({ level: 'overview' }));
      }
    } catch (e) { /* storage may be unavailable */ }
  };

  const readOwnerNavigation = () => {
    try {
      const value = JSON.parse(localStorage.getItem(OWNER_NAV_KEY) || 'null');
      if (!value || typeof value !== 'object') return null;
      if (value.level === 'owner' && value.ownerKey) {
        return {
          level: 'owner',
          ownerKey: String(value.ownerKey).slice(0, 300),
          ownerName: String(value.ownerName || '').slice(0, 300),
        };
      }
    } catch (e) { /* ignore */ }
    return null;
  };

  const saveSelectedSources = (keys) => {
    try {
      localStorage.setItem(SOURCES_KEY, JSON.stringify(keys));
    } catch (e) { /* storage may be unavailable */ }
  };

  const syncScopesFromStorage = () => {
    const checks = Array.from(root.querySelectorAll('.sp-owner-storage-scope-check'));
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
    const checks = Array.from(root.querySelectorAll('.sp-owner-storage-scope-check'));
    if (!checks.length) return;
    const selected = checks.filter((el) => el.checked).length;
    const countEl = document.getElementById('sp-owner-storage-scopes-count');
    if (countEl) countEl.textContent = `${selected} of ${checks.length}`;
    const allBtn = document.getElementById('sp-owner-storage-scopes-all');
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
    root.querySelectorAll('.sp-size-scope-check, .sp-duplicates-scope-check').forEach((input) => {
      input.checked = selected.has(input.value);
      input.closest('.sharepoint-scope-chip')?.classList.toggle('is-active', input.checked);
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
    if (sized.length <= tileLimit) return sized;
    const top = sized.slice(0, tileLimit - 1);
    const rest = sized.slice(tileLimit - 1);
    const otherBytes = rest.reduce((sum, node) => sum + (Number(node.size_bytes) || 0), 0);
    const otherFiles = rest.reduce((sum, node) => sum + (Number(node.file_count) || 0), 0);
    const otherProjects = rest.reduce((sum, node) => sum + (Number(node.project_count) || 0), 0);
    top.push({
      key: '__other__',
      owner_key: '',
      label: `Other (${rest.length})`,
      type: 'other',
      size_bytes: otherBytes,
      file_count: otherFiles,
      project_count: otherProjects,
      avg_file_size: otherFiles ? Math.round(otherBytes / otherFiles) : 0,
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

  const ownerDashUrl = (ownerKey) => {
    const url = new URL('sharepoint.php', window.location.href);
    url.search = '';
    url.searchParams.set('view', 'owners');
    if (ownerKey) url.searchParams.set('owner', ownerKey);
    return url.toString();
  };

  const renderTreemap = (nodes) => {
    const { width, height } = getTreemapSize();
    treemapEl.style.width = '100%';
    treemapEl.style.height = `${height}px`;
    treemapEl.style.maxHeight = `${height}px`;

    const rects = squarify(nodes, 0, 0, width, height);
    if (!rects.length) {
      treemapEl.innerHTML = `<p class="sp-size-empty">${
        state.level === 'owner' ? 'No projects for this owner.' : 'No owner storage data for this view.'
      }</p>`;
      return;
    }

    treemapEl.innerHTML = rects
      .map(({ node, x, y, width: w, height: h }) => {
        const label = String(node.label || node.key || '');
        const bytes = Number(node.size_bytes) || 0;
        const files = Number(node.file_count) || 0;
        const projects = Number(node.project_count) || 0;
        const avg = Number(node.avg_file_size) || 0;
        const type = String(node.type || 'owner');
        const ownerKey = String(node.owner_key || node.key || '');
        const sourceKey = String(node.source_key || '');
        const projectName = String(node.project_name || node.label || '');
        const folderUrl = String(node.folder_url || '');
        const sourceTitle = String(node.source_title || '');
        const showLabel = w >= MIN_TILE_PX && h >= MIN_TILE_PX;
        const tip = [
          label,
          sourceTitle && type === 'project' ? sourceTitle : '',
          formatBytes(bytes),
          files ? `${files} file(s)` : '',
          projects ? `${projects} project folder(s)` : '',
          avg ? `avg ${formatBytes(avg)}` : '',
          type === 'owner' ? 'Click to show this owner’s projects' : '',
          type === 'project' ? (folderUrl ? 'Click to open project folder' : 'Project folder') : '',
        ]
          .filter(Boolean)
          .join('\n');
        const drillable =
          (type === 'owner' && ownerKey && ownerKey !== '__other__') ||
          type === 'project';
        const left = Math.max(0, Math.min(x, width - 1));
        const top = Math.max(0, Math.min(y, height - 1));
        const tileW = Math.max(1, Math.min(w, width - left));
        const tileH = Math.max(1, Math.min(h, height - top));
        return `<button type="button" class="sp-size-tile sp-owner-storage-tile${drillable ? ' is-drillable' : ''}${type === 'other' ? ' is-other' : ''}${type === 'project' ? ' is-project' : ''}"
          style="left:${left}px;top:${top}px;width:${tileW}px;height:${tileH}px;background:${nodeColor(node.hue)}"
          data-type="${escapeAttr(type)}"
          data-owner-key="${escapeAttr(ownerKey)}"
          data-owner-name="${escapeAttr(node.owner_name || label)}"
          data-source-key="${escapeAttr(sourceKey)}"
          data-project-name="${escapeAttr(projectName)}"
          data-folder-url="${escapeAttr(folderUrl)}"
          data-label="${escapeAttr(label)}"
          title="${escapeAttr(tip)}"
          aria-label="${escapeAttr(`${label}, ${formatBytes(bytes)}`)}"
          ${drillable ? '' : ' tabindex="-1"'}
        >${showLabel ? `<span class="sp-size-tile-label">${escapeHtml(label)}</span><span class="sp-size-tile-size">${escapeHtml(formatBytes(bytes))}</span>` : `<span class="sp-size-tile-dot" aria-hidden="true"></span>`}</button>`;
      })
      .join('');
    replayHeatmapAnimation();
  };

  const renderBreadcrumb = () => {
    if (!breadcrumbEl) return;
    if (state.level === 'owner' && state.ownerKey) {
      breadcrumbEl.innerHTML = `
        <button type="button" class="sp-size-crumb" data-owner-level="overview">All owners</button>
        <span class="sp-size-crumb-sep" aria-hidden="true">›</span>
        <button type="button" class="sp-size-crumb is-active" data-owner-level="owner" aria-current="location">${escapeHtml(state.ownerName || state.ownerKey)}</button>
      `;
    } else {
      breadcrumbEl.innerHTML = `<button type="button" class="sp-size-crumb is-active" data-owner-level="overview" aria-current="location">All owners</button>`;
    }
    if (backBtn) backBtn.hidden = state.level !== 'owner';
    if (scopesRoot) scopesRoot.hidden = state.level === 'owner';
    if (ownerDashLink) {
      ownerDashLink.href = ownerDashUrl(state.level === 'owner' ? state.ownerKey : '');
    }
  };

  const renderKpis = (kpis) => {
    if (!kpisEl) return;
    if (!kpis) {
      kpisEl.innerHTML = '';
      return;
    }
    const cards =
      state.level === 'owner'
        ? [
            { label: 'Owner storage', value: formatBytes(kpis.total_bytes) },
            { label: 'Projects', value: String(kpis.project_count ?? 0) },
            { label: 'Files', value: String(kpis.file_count ?? 0) },
            { label: 'Avg file size', value: formatBytes(kpis.avg_file_size) },
          ]
        : [
            { label: 'Total storage', value: formatBytes(kpis.total_bytes) },
            { label: 'Owners', value: String(kpis.total_owners ?? 0) },
            { label: 'Files', value: String(kpis.file_count ?? 0) },
            { label: 'Avg file size', value: formatBytes(kpis.avg_file_size) },
            {
              label: 'Top owner',
              value: kpis.top_owner_name ? formatBytes(kpis.top_owner_bytes) : '—',
              hint: kpis.top_owner_name
                ? `${kpis.top_owner_name} · ${formatPct(kpis.concentration_pct)}`
                : 'No owners yet',
              owner: kpis.top_owner_key || '',
              warn: !!kpis.concentration_warn,
            },
          ];
    kpisEl.innerHTML = cards
      .map((card) => {
        const tag = card.owner ? 'button' : 'div';
        const attrs = card.owner
          ? ` type="button" data-owner-key="${escapeAttr(card.owner)}" data-owner-name="${escapeAttr(kpis.top_owner_name || '')}" class="sp-size-kpi sp-owner-storage-kpi-btn${card.warn ? ' is-warn' : ''}"`
          : ` class="sp-size-kpi${card.warn ? ' is-warn' : ''}"`;
        return `<${tag}${attrs}>
          <span class="sp-size-kpi-label">${escapeHtml(card.label)}</span>
          <strong class="sp-size-kpi-value">${escapeHtml(card.value)}</strong>
          ${card.hint ? `<span class="sp-size-kpi-hint">${escapeHtml(card.hint)}</span>` : ''}
        </${tag}>`;
      })
      .join('');
  };

  const renderQuality = (kpis) => {
    if (!qualityEl) return;
    if (state.level === 'owner' || !kpis?.concentration_warn || !kpis.top_owner_name) {
      qualityEl.hidden = true;
      qualityEl.innerHTML = '';
      return;
    }
    qualityEl.hidden = false;
    qualityEl.innerHTML = `<button type="button" class="sp-owner-storage-warn" data-owner-key="${escapeAttr(kpis.top_owner_key || '')}" data-owner-name="${escapeAttr(kpis.top_owner_name || '')}">
      ${escapeHtml(kpis.top_owner_name)} owns ${escapeHtml(formatPct(kpis.concentration_pct))} of catalog storage — click to drill in
    </button>`;
  };

  const compareOwnerFiles = (a, b, key) => {
    if (key === 'size') {
      return (Number(a.size_bytes) || 0) - (Number(b.size_bytes) || 0);
    }
    if (key === 'created') {
      return createdSortKey(a.date_created).localeCompare(createdSortKey(b.date_created));
    }
    const left =
      key === 'owner' ? a.owner_name : key === 'project' ? a.project_name : a.name;
    const right =
      key === 'owner' ? b.owner_name : key === 'project' ? b.project_name : b.name;
    return String(left || '').localeCompare(String(right || ''), undefined, {
      numeric: true,
      sensitivity: 'base',
    });
  };

  const syncFileSortHeaders = () => {
    filesTable?.querySelectorAll('th[data-owner-file-sort]').forEach((header) => {
      const active = header.getAttribute('data-owner-file-sort') === state.fileSort.key;
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
      .sort((a, b) => compareOwnerFiles(a, b, state.fileSort.key) * multiplier);
    syncFileSortHeaders();
    if (filesHelp) {
      filesHelp.textContent = rows.length
        ? state.level === 'owner'
          ? `Top ${rows.length} largest files for ${state.ownerName || 'this owner'}`
          : `Top ${rows.length} largest files across selected catalogs`
        : state.level === 'owner'
          ? 'No files for this owner'
          : 'No files in selected catalogs';
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
        const ownerKey = String(file.owner_key || '');
        const ownerName = String(file.owner_name || '—');
        const ownerCell =
          ownerKey
            ? `<button type="button" class="sp-owner-storage-owner-link" data-owner-key="${escapeAttr(ownerKey)}" data-owner-name="${escapeAttr(ownerName)}">${escapeHtml(ownerName)}</button>`
            : escapeHtml(ownerName);
        return `<tr>
          <td class="sp-size-file-name">${nameCell}</td>
          <td>${ownerCell}</td>
          <td>${escapeHtml(file.project_name || '')}</td>
          <td class="sp-size-file-bytes">${escapeHtml(formatBytes(file.size_bytes))}</td>
          <td>${escapeHtml(formatCreated(file.date_created))}</td>
        </tr>`;
      })
      .join('');
  };

  const applyPayload = (payload) => {
    state.data = payload;
    state.level = payload.level === 'owner_projects' ? 'owner' : 'overview';
    if (state.level === 'owner') {
      state.ownerKey = String(payload.owner_key || state.ownerKey || '');
      state.ownerName = String(payload.owner_name || state.ownerName || state.ownerKey);
    } else {
      state.ownerKey = '';
      state.ownerName = '';
    }
    saveOwnerNavigation();
    renderBreadcrumb();
    renderKpis(payload.kpis || {});
    renderQuality(payload.kpis || {});
    renderTreemap(payload.nodes || []);
    renderLargeFiles(payload.large_files || []);
  };

  const load = async (force = false) => {
    if (state.loading) return;
    if (state.loaded && !force && state.data && state.level === 'overview' && !state.ownerKey) {
      renderTreemap(state.data.nodes || []);
      return;
    }
    state.loading = true;
    treemapEl.innerHTML = '<p class="sp-size-empty">Loading…</p>';
    if (filesBody) {
      filesBody.innerHTML = '<tr><td colspan="5" class="sp-size-empty">Loading…</td></tr>';
    }
    try {
      const sources = readSelectedSources();
      const params = { sources: sources.join(',') || 'all' };
      if (state.level === 'owner' && state.ownerKey) {
        params.owner = state.ownerKey;
      }
      const response = await fetch(apiUrl(params), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || 'Failed to load owner storage data.');
      }
      applyPayload(payload);
      state.loaded = true;
    } catch (error) {
      treemapEl.innerHTML = `<p class="sp-size-empty sp-size-error">${escapeHtml(error.message || 'Load failed.')}</p>`;
      if (filesBody) {
        filesBody.innerHTML = `<tr><td colspan="5" class="sp-size-empty sp-size-error">${escapeHtml(error.message || 'Load failed.')}</td></tr>`;
      }
      if (kpisEl) kpisEl.innerHTML = '';
      if (qualityEl) {
        qualityEl.hidden = true;
        qualityEl.innerHTML = '';
      }
    } finally {
      state.loading = false;
    }
  };

  const drillIntoOwner = (ownerKey, ownerName = '') => {
    if (!ownerKey) return;
    state.level = 'owner';
    state.ownerKey = ownerKey;
    state.ownerName = ownerName || ownerKey;
    state.loaded = false;
    saveOwnerNavigation();
    load(true);
  };

  const showOverview = () => {
    state.level = 'overview';
    state.ownerKey = '';
    state.ownerName = '';
    state.loaded = false;
    saveOwnerNavigation();
    load(true);
  };

  const openProject = (sourceKey, projectName, folderUrl) => {
    if (typeof window.RiskRegisterSharePoint?.openProject === 'function' && projectName) {
      window.RiskRegisterSharePoint.openProject(projectName, sourceKey);
      return;
    }
    if (folderUrl) {
      window.open(folderUrl, '_blank', 'noopener,noreferrer');
    }
  };

  treemapEl.addEventListener('click', (event) => {
    const tile = event.target.closest('.sp-owner-storage-tile');
    if (!tile) return;
    const type = tile.getAttribute('data-type') || '';
    if (type === 'owner') {
      drillIntoOwner(
        tile.getAttribute('data-owner-key') || '',
        tile.getAttribute('data-owner-name') || tile.getAttribute('data-label') || ''
      );
      return;
    }
    if (type === 'project') {
      openProject(
        tile.getAttribute('data-source-key') || '',
        tile.getAttribute('data-project-name') || '',
        tile.getAttribute('data-folder-url') || ''
      );
    }
  });

  const ownerDrillHandler = (event) => {
    const btn = event.target.closest('[data-owner-key]');
    if (!btn) return;
    event.preventDefault();
    drillIntoOwner(
      btn.getAttribute('data-owner-key') || '',
      btn.getAttribute('data-owner-name') || btn.textContent?.trim() || ''
    );
  };

  kpisEl?.addEventListener('click', ownerDrillHandler);
  qualityEl?.addEventListener('click', ownerDrillHandler);
  filesBody?.addEventListener('click', ownerDrillHandler);

  breadcrumbEl?.addEventListener('click', (event) => {
    const crumb = event.target.closest('[data-owner-level]');
    if (!crumb || crumb.classList.contains('is-active')) return;
    if (crumb.getAttribute('data-owner-level') === 'overview') showOverview();
  });

  backBtn?.addEventListener('click', showOverview);
  refreshBtn?.addEventListener('click', () => load(true));

  filesTable?.querySelector('thead')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-owner-file-sort-button]');
    if (!button) return;
    const key = button.getAttribute('data-owner-file-sort-button') || 'name';
    if (state.fileSort.key === key) {
      state.fileSort.direction = state.fileSort.direction === 'asc' ? 'desc' : 'asc';
    } else {
      state.fileSort.key = key;
      state.fileSort.direction = key === 'size' || key === 'created' ? 'desc' : 'asc';
    }
    renderLargeFiles(state.largeFiles);
  });

  scopesRoot?.addEventListener('change', (event) => {
    if (!event.target.classList.contains('sp-owner-storage-scope-check')) return;
    const checks = root.querySelectorAll('.sp-owner-storage-scope-check:checked');
    if (!checks.length) {
      event.target.checked = true;
      return;
    }
    const keys = readSelectedSources();
    saveSelectedSources(keys);
    syncOtherScopeChecks(keys);
    updateScopesUi();
    showOverview();
  });

  document.getElementById('sp-owner-storage-scopes-all')?.addEventListener('click', () => {
    root.querySelectorAll('.sp-owner-storage-scope-check').forEach((el) => {
      el.checked = true;
    });
    const keys = readSelectedSources();
    saveSelectedSources(keys);
    syncOtherScopeChecks(keys);
    updateScopesUi();
    showOverview();
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
  const savedOwnerNav = readOwnerNavigation();
  if (savedOwnerNav?.ownerKey) {
    state.level = 'owner';
    state.ownerKey = savedOwnerNav.ownerKey;
    state.ownerName = savedOwnerNav.ownerName || savedOwnerNav.ownerKey;
  }
  renderBreadcrumb();

  window.RiskRegisterOwnerStorage = {
    load: (force = false) => {
      if (force) {
        state.loaded = false;
      }
      // First open after page load: restore drilled-in owner if we have one saved.
      if (!state.loaded && !force && state.level === 'overview' && !state.ownerKey) {
        const saved = readOwnerNavigation();
        if (saved?.ownerKey) {
          state.level = 'owner';
          state.ownerKey = saved.ownerKey;
          state.ownerName = saved.ownerName || saved.ownerKey;
        }
      }
      return load(force);
    },
    isLoaded: () => state.loaded,
    invalidate: () => {
      state.loaded = false;
      state.data = null;
      state.level = 'overview';
      state.ownerKey = '';
      state.ownerName = '';
      saveOwnerNavigation();
    },
    redraw: () => {
      if (state.loaded && state.data?.nodes) renderTreemap(state.data.nodes || []);
    },
    syncScopesFromStorage,
  };
})();
