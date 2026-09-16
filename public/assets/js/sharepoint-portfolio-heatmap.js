(() => {
  const root = document.getElementById('sharepoint-size-heatmap');
  const treemapEl = document.getElementById('sp-portfolio-treemap');
  const treemapWrap = treemapEl?.closest('.sp-portfolio-treemap-wrap');
  const kpisEl = document.getElementById('sp-portfolio-kpis');
  const coverageEl = document.getElementById('sp-portfolio-coverage');
  const filesBody = document.getElementById('sp-portfolio-files-body');
  const filesHelp = document.getElementById('sp-portfolio-files-help');
  const filesTable = document.getElementById('sp-portfolio-files-table');
  const projectBody = document.getElementById('sp-portfolio-project-body');
  const projectHelp = document.getElementById('sp-portfolio-project-help');
  const projectTable = document.getElementById('sp-portfolio-project-table');
  const projectCountEl = document.getElementById('sp-portfolio-project-count');
  const projectSearch = document.getElementById('sp-portfolio-project-search');
  const projectUniqueToggle = document.getElementById('sp-portfolio-project-unique');
  const chartFiltersEl = document.getElementById('sp-portfolio-chart-filters');
  const scopesRoot = document.getElementById('sp-portfolio-scopes');
  const refreshBtn = document.getElementById('sp-portfolio-refresh');
  const backBtn = document.getElementById('sp-portfolio-back');
  const breadcrumbEl = document.getElementById('sp-portfolio-breadcrumb');
  if (!root || !treemapEl) return;

  const PORTFOLIO_SOURCES_KEY = 'riskregister_sp_portfolio_heatmap_sources';
  const NAV_KEY = 'riskregister_sp_portfolio_heatmap_navigation';
  const MODE_KEY = 'riskregister_sp_portfolio_heatmap_mode';
  const CHART_KEY = 'riskregister_sp_portfolio_heatmap_chart';
  const CHART_SORT_KEY = 'riskregister_sp_portfolio_heatmap_chart_sort';
  const MIN_TILE_PX = 44;

  const state = {
    loaded: false,
    loading: false,
    data: null,
    level: 'portfolios',
    mode: 'storage',
    chartType: 'heatmap',
    chartSort: 'desc',
    portfolio: '',
    subPortfolio: '',
    ownerKey: '',
    ownerName: '',
    largeFiles: [],
    projectMenu: [],
    projectSearch: '',
    projectUniqueOnly: false,
    projectFilters: {
      source: '',
      owner: '',
      portfolio: '',
      sub: '',
    },
    fileSort: { key: 'size', direction: 'desc' },
    projectSort: { key: 'size', direction: 'desc' },
    canEditPortfolio: root.getAttribute('data-can-edit-portfolio') === '1',
    mappingOptions: null,
    mappingOptionsLoading: null,
  };

  const normalizeMode = (value) => {
    const mode = String(value || '');
    if (mode === 'projects' || mode === 'owners') return mode;
    return 'storage';
  };

  const CHART_TYPES = new Set(['heatmap', 'bar', 'columns', 'share', 'rank', 'pareto', 'mix', 'bubbles']);
  const SORTABLE_CHART_TYPES = new Set(['bar', 'columns', 'share', 'rank', 'pareto', 'mix', 'bubbles']);
  const normalizeChartType = (value) => {
    const chart = String(value || '');
    return CHART_TYPES.has(chart) ? chart : 'heatmap';
  };
  const normalizeChartSort = (value) => (String(value || '') === 'asc' ? 'asc' : 'desc');
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
    const qs = new URLSearchParams();
    qs.set('action', 'portfolio_stats');
    Object.entries(params).forEach(([key, value]) => {
      if (value === undefined || value === null || String(value) === '') return;
      qs.set(key, String(value));
    });
    return `sharepoint.php?${qs.toString()}`;
  };

  const readSelectedSources = () => {
    const checks = root.querySelectorAll('.sp-portfolio-scope-check:checked');
    const keys = Array.from(checks).map((el) => el.value).filter(Boolean);
    if (keys.length) return keys;
    const first = root.querySelector('.sp-portfolio-scope-check');
    return first?.value ? [first.value] : [];
  };

  const saveSelectedSources = (keys) => {
    try {
      localStorage.setItem(PORTFOLIO_SOURCES_KEY, JSON.stringify(keys));
    } catch (e) { /* storage may be unavailable */ }
  };

  const saveNavigation = () => {
    try {
      localStorage.setItem(NAV_KEY, JSON.stringify({
        level: state.level,
        mode: state.mode,
        portfolio: String(state.portfolio || '').slice(0, 300),
        subPortfolio: String(state.subPortfolio || '').slice(0, 300),
        ownerKey: String(state.ownerKey || '').slice(0, 300),
        ownerName: String(state.ownerName || '').slice(0, 300),
      }));
    } catch (e) { /* ignore */ }
  };

  const readNavigation = () => {
    try {
      const value = JSON.parse(localStorage.getItem(NAV_KEY) || 'null');
      if (!value || typeof value !== 'object') return null;
      const mode = normalizeMode(value.mode);
      const level = String(value.level || '');
      const portfolio = String(value.portfolio || '').slice(0, 300);
      const subPortfolio = String(value.subPortfolio || '').slice(0, 300);
      const ownerKey = String(value.ownerKey || '').slice(0, 300);
      const ownerName = String(value.ownerName || '').slice(0, 300);
      if (level === 'projects' && portfolio) {
        return {
          level,
          mode,
          portfolio,
          subPortfolio: mode === 'storage' ? subPortfolio : '',
          ownerKey: mode === 'owners' ? ownerKey : '',
          ownerName: mode === 'owners' ? ownerName : '',
        };
      }
      if (level === 'owners' && portfolio && mode === 'owners') {
        return { level, mode, portfolio, subPortfolio: '', ownerKey: '', ownerName: '' };
      }
      if (level === 'sub_portfolios' && portfolio && mode === 'storage') {
        return { level, mode, portfolio, subPortfolio: '', ownerKey: '', ownerName: '' };
      }
      if (level === 'portfolios') {
        return { level: 'portfolios', mode, portfolio: '', subPortfolio: '', ownerKey: '', ownerName: '' };
      }
    } catch (e) { /* ignore */ }
    return null;
  };

  const readPersistedMode = () => {
    try {
      return normalizeMode(localStorage.getItem(MODE_KEY) || '');
    } catch (e) { /* ignore */ }
    return 'storage';
  };

  const readPersistedChartType = () => {
    try {
      return normalizeChartType(localStorage.getItem(CHART_KEY) || '');
    } catch (e) { /* ignore */ }
    return 'heatmap';
  };

  const readPersistedChartSort = () => {
    try {
      return normalizeChartSort(localStorage.getItem(CHART_SORT_KEY) || '');
    } catch (e) { /* ignore */ }
    return 'desc';
  };

  const setModeUi = () => {
    root.querySelectorAll('[data-portfolio-mode]').forEach((btn) => {
      const active = btn.getAttribute('data-portfolio-mode') === state.mode;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  };

  const setChartTypeUi = () => {
    root.querySelectorAll('[data-portfolio-chart]').forEach((btn) => {
      const active = btn.getAttribute('data-portfolio-chart') === state.chartType;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    root.querySelectorAll('[data-portfolio-chart-sort]').forEach((btn) => {
      const active = btn.getAttribute('data-portfolio-chart-sort') === state.chartSort;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    const sortGroup = document.getElementById('sp-portfolio-chart-sort');
    if (sortGroup) {
      sortGroup.hidden = !SORTABLE_CHART_TYPES.has(state.chartType);
    }
    treemapEl?.setAttribute('data-chart-type', state.chartType);
    treemapEl?.setAttribute('data-chart-sort', state.chartSort);
    const scrollable = state.chartType !== 'heatmap';
    if (treemapWrap) {
      treemapWrap.classList.toggle('is-scroll-chart', scrollable);
      treemapWrap.classList.toggle('is-bar-chart', scrollable);
    }
  };

  const sortedChartNodes = (nodes) => {
    const list = (nodes || [])
      .slice()
      .filter((node) => nodeWeight(node) > 0 || (Number(node.size_bytes) || 0) > 0 || (Number(node.project_count) || 0) > 0)
      .sort((a, b) => {
        const cmp = nodeWeight(b) - nodeWeight(a);
        if (cmp !== 0) return cmp;
        return String(a.label || '').localeCompare(String(b.label || ''), undefined, { sensitivity: 'base' });
      });
    if (state.chartSort === 'asc') list.reverse();
    return list;
  };

  const prepareAltChartShell = (chartClass, heightPx) => {
    const height = Math.max(280, Number(heightPx) || 320);
    treemapEl.style.width = '100%';
    treemapEl.style.height = `${height}px`;
    treemapEl.style.maxHeight = `${Math.min(780, Math.max(320, height))}px`;
    treemapEl.classList.remove(
      'is-bar-chart',
      'is-columns-chart',
      'is-share-chart',
      'is-rank-chart',
      'is-pareto-chart',
      'is-mix-chart',
      'is-bubbles-chart',
      'is-chart-enter'
    );
    if (chartClass) treemapEl.classList.add(chartClass);
  };

  const playChartEntrance = () => {
    if (!treemapEl) return;
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches) return;
    treemapEl.classList.remove('is-chart-enter');
    // Force reflow so repeated renders replay the entrance.
    void treemapEl.offsetWidth;
    treemapEl.classList.add('is-chart-enter');
    window.clearTimeout(playChartEntrance._timer);
    playChartEntrance._timer = window.setTimeout(() => {
      treemapEl.classList.remove('is-chart-enter');
    }, 1200);
  };

  const weightSharePct = (weight, total) => {
    if (!total) return 0;
    return Math.round((weight / total) * 1000) / 10;
  };

  const nodeWeight = (node) => {
    const type = String(node.type || '');
    if (state.mode === 'projects' && type !== 'project') {
      return Math.max(0, Number(node.project_count) || 0);
    }
    // Owners mode: size by projects so portfolios with one owner still differ visually.
    if (state.mode === 'owners' && (type === 'portfolio' || type === 'owner')) {
      return Math.max(0, Number(node.project_count) || 0);
    }
    return Math.max(0, Number(node.size_bytes) || 0);
  };

  const formatProjectCount = (count) => {
    const n = Number(count) || 0;
    return `${n} project${n === 1 ? '' : 's'}`;
  };

  const formatOwnerCount = (count) => {
    const n = Number(count) || 0;
    return `${n} owner${n === 1 ? '' : 's'}`;
  };

  const tileSecondaryLabel = (node, type) => {
    const bytes = Number(node.size_bytes) || 0;
    const projects = Number(node.project_count) || 0;
    const owners = Number(node.owner_count) || 0;
    if (type === 'project') {
      return formatBytes(bytes);
    }
    if (type === 'owner') {
      return `${formatProjectCount(projects)} · ${formatBytes(bytes)}`;
    }
    if (state.mode === 'owners' && type === 'portfolio') {
      return `${formatOwnerCount(owners)} · ${formatProjectCount(projects)}`;
    }
    if (state.mode === 'projects') {
      return `${formatProjectCount(projects)} · ${formatBytes(bytes)}`;
    }
    return `${formatBytes(bytes)} · ${formatProjectCount(projects)}`;
  };

  const syncScopesFromStorage = () => {
    const checks = Array.from(root.querySelectorAll('.sp-portfolio-scope-check'));
    if (!checks.length) return;
    let stored = null;
    try {
      const parsed = JSON.parse(localStorage.getItem(PORTFOLIO_SOURCES_KEY) || 'null');
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
    const checks = Array.from(root.querySelectorAll('.sp-portfolio-scope-check'));
    if (!checks.length) return;
    const selected = checks.filter((el) => el.checked).length;
    const countEl = document.getElementById('sp-portfolio-scopes-count');
    if (countEl) countEl.textContent = `${selected} of ${checks.length}`;
    const allBtn = document.getElementById('sp-portfolio-scopes-all');
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
      .filter((node) => nodeWeight(node) > 0)
      .slice()
      .sort((a, b) => nodeWeight(b) - nodeWeight(a));
    const tileLimit = maxTreemapTiles();
    if (sized.length <= tileLimit) return sized;
    const top = sized.slice(0, tileLimit - 1);
    const rest = sized.slice(tileLimit - 1);
    const otherBytes = rest.reduce((sum, node) => sum + (Number(node.size_bytes) || 0), 0);
    const otherFiles = rest.reduce((sum, node) => sum + (Number(node.file_count) || 0), 0);
    const otherProjects = rest.reduce((sum, node) => {
      const type = String(node.type || '');
      if (type === 'project') return sum + 1;
      return sum + (Number(node.project_count) || 0);
    }, 0);
    top.push({
      key: '__other__',
      label: `Other (${rest.length})`,
      type: 'other',
      size_bytes: otherBytes,
      file_count: otherFiles,
      project_count: otherProjects,
      hue: 215,
    });
    return top;
  };

  const squarify = (nodes, x, y, width, height) => {
    const prepared = prepareNodes(nodes);
    const total = prepared.reduce((sum, node) => sum + nodeWeight(node), 0);
    const pixelArea = width * height;
    const items = prepared.map((node) => ({
      node,
      area: (nodeWeight(node) / total) * pixelArea,
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

  const emptyChartMessage = () => {
    if (state.level === 'projects') return 'No projects in this category.';
    if (state.level === 'owners') return 'No owners in this portfolio.';
    if (state.level === 'sub_portfolios') return 'No sub-portfolios in this portfolio.';
    return 'No portfolio storage data for this view.';
  };

  const nodeButtonAttrs = (node) => {
    const label = String(node.label || node.key || '');
    const bytes = Number(node.size_bytes) || 0;
    const files = Number(node.file_count) || 0;
    const projects = Number(node.project_count) || 0;
    const owners = Number(node.owner_count) || 0;
    const type = String(node.type || 'portfolio');
    const sourceKey = String(node.source_key || '');
    const projectName = String(node.project_name || (type === 'project' ? label : ''));
    const ownerKey = String(node.owner_key || (type === 'owner' ? node.key : ''));
    const ownerName = String(node.owner_name || (type === 'owner' ? label : ''));
    const confidence = String(node.confidence || '');
    const needsReview = !!node.needs_review;
    const secondary = tileSecondaryLabel(node, type);
    const tip = [
      label,
      type === 'project' && node.source_title ? String(node.source_title) : '',
      type === 'owner' ? formatProjectCount(projects) : '',
      type === 'portfolio' && state.mode === 'owners' ? formatOwnerCount(owners) : '',
      type !== 'project' && type !== 'owner' ? formatProjectCount(projects) : '',
      formatBytes(bytes),
      files ? `${files} file(s)` : '',
      confidence ? `Confidence: ${confidence}` : '',
      needsReview ? 'Needs review' : '',
      type === 'portfolio' && state.mode === 'projects' ? 'Click to show projects heatmap' : '',
      type === 'portfolio' && state.mode === 'owners' ? 'Click to show owners' : '',
      type === 'portfolio' && state.mode === 'storage' ? 'Click to show sub-portfolios' : '',
      type === 'owner' ? 'Click to show this owner’s projects' : '',
      type === 'sub_portfolio' ? 'Click to show projects' : '',
      type === 'project' ? 'Click to open project dialog' : '',
    ]
      .filter(Boolean)
      .join('\n');
    const drillable =
      (type === 'portfolio' || type === 'sub_portfolio' || type === 'owner' || type === 'project') &&
      label &&
      label !== '__other__';
    let ariaBits = `${label}, ${formatBytes(bytes)}`;
    if (type === 'owner') {
      ariaBits = `${label}, ${formatProjectCount(projects)}, ${formatBytes(bytes)}`;
    } else if (type === 'portfolio' && state.mode === 'owners') {
      ariaBits = `${label}, ${formatOwnerCount(owners)}, ${formatProjectCount(projects)}`;
    } else if (type !== 'project') {
      ariaBits = `${label}, ${formatBytes(bytes)}, ${formatProjectCount(projects)}`;
    }
    return {
      label,
      type,
      secondary,
      tip,
      drillable,
      needsReview,
      ariaBits,
      sourceKey,
      projectName,
      ownerKey,
      ownerName,
      color: nodeColor(node.hue, needsReview ? 0.55 : 0.82),
      attrs: `
          data-type="${escapeAttr(type)}"
          data-key="${escapeAttr(String(node.key || label))}"
          data-label="${escapeAttr(label)}"
          data-portfolio="${escapeAttr(String(node.portfolio || (type === 'portfolio' ? label : state.portfolio)))}"
          data-sub-portfolio="${escapeAttr(String(node.sub_portfolio || (type === 'sub_portfolio' ? label : state.subPortfolio)))}"
          data-owner-key="${escapeAttr(ownerKey)}"
          data-owner-name="${escapeAttr(ownerName)}"
          data-source-key="${escapeAttr(sourceKey)}"
          data-project-name="${escapeAttr(projectName)}"
          title="${escapeAttr(tip)}"
          aria-label="${escapeAttr(ariaBits)}"
          ${drillable ? '' : ' tabindex="-1"'}`,
    };
  };

  const renderTreemap = (nodes) => {
    const { width, height } = getTreemapSize();
    treemapEl.style.width = '100%';
    treemapEl.style.height = `${height}px`;
    treemapEl.style.maxHeight = `${height}px`;
    treemapEl.classList.remove(
      'is-bar-chart',
      'is-columns-chart',
      'is-share-chart',
      'is-rank-chart',
      'is-pareto-chart',
      'is-mix-chart',
      'is-bubbles-chart',
      'is-chart-enter'
    );

    const rects = squarify(nodes, 0, 0, width, height);
    if (!rects.length) {
      treemapEl.innerHTML = `<p class="sp-size-empty">${emptyChartMessage()}</p>`;
      return;
    }

    treemapEl.innerHTML = rects
      .map(({ node, x, y, width: w, height: h }) => {
        const meta = nodeButtonAttrs(node);
        const showLabel = w >= MIN_TILE_PX && h >= MIN_TILE_PX;
        const left = Math.max(0, Math.min(x, width - 1));
        const top = Math.max(0, Math.min(y, height - 1));
        const tileW = Math.max(1, Math.min(w, width - left));
        const tileH = Math.max(1, Math.min(h, height - top));
        const reviewClass = meta.needsReview ? ' is-needs-review' : '';
        return `<button type="button" class="sp-size-tile sp-portfolio-tile${meta.drillable ? ' is-drillable' : ''}${meta.type === 'other' ? ' is-other' : ''}${meta.type === 'project' ? ' is-project' : ''}${meta.type === 'owner' ? ' is-owner' : ''}${reviewClass}"
          style="left:${left}px;top:${top}px;width:${tileW}px;height:${tileH}px;background:${meta.color}"
          ${meta.attrs}
        >${showLabel ? `<span class="sp-size-tile-label">${escapeHtml(meta.label)}</span><span class="sp-size-tile-size">${escapeHtml(meta.secondary)}</span>${meta.needsReview && showLabel ? '<span class="sp-portfolio-tile-badge">Review</span>' : ''}` : `<span class="sp-size-tile-dot" aria-hidden="true"></span>`}</button>`;
      })
      .join('');
    replayHeatmapAnimation();
  };

  const renderBarChart = (nodes) => {
    const sorted = sortedChartNodes(nodes);
    const { height: minHeight } = getTreemapSize();
    const chartHeight = Math.max(minHeight, Math.min(720, sorted.length * 38 + 16));
    prepareAltChartShell('is-bar-chart', chartHeight);

    if (!sorted.length) {
      treemapEl.innerHTML = `<p class="sp-size-empty">${emptyChartMessage()}</p>`;
      return;
    }

    const maxWeight = Math.max(...sorted.map((node) => nodeWeight(node)), 1);
    treemapEl.innerHTML = `<div class="sp-portfolio-bars" role="list">${sorted
      .map((node, index) => {
        const meta = nodeButtonAttrs(node);
        const weight = nodeWeight(node);
        const pct = Math.max(2, Math.round((weight / maxWeight) * 100));
        const reviewClass = meta.needsReview ? ' is-needs-review' : '';
        return `<button type="button" class="sp-portfolio-bar sp-portfolio-tile sp-chart-enter-item${meta.drillable ? ' is-drillable' : ''}${meta.type === 'project' ? ' is-project' : ''}${meta.type === 'owner' ? ' is-owner' : ''}${reviewClass}"
          role="listitem"
          style="--i:${index}"
          ${meta.attrs}
        >
          <span class="sp-portfolio-bar-label">${escapeHtml(meta.label)}${meta.needsReview ? ' <em>Review</em>' : ''}</span>
          <span class="sp-portfolio-bar-track" aria-hidden="true">
            <span class="sp-portfolio-bar-fill" style="--fill:${pct}%;background:${meta.color}"></span>
          </span>
          <span class="sp-portfolio-bar-value">${escapeHtml(meta.secondary)}</span>
        </button>`;
      })
      .join('')}</div>`;
    playChartEntrance();
  };

  const renderColumnsChart = (nodes) => {
    const sorted = sortedChartNodes(nodes);
    const { height: minHeight } = getTreemapSize();
    prepareAltChartShell('is-columns-chart', Math.max(minHeight, 360));

    if (!sorted.length) {
      treemapEl.innerHTML = `<p class="sp-size-empty">${emptyChartMessage()}</p>`;
      return;
    }

    const maxWeight = Math.max(...sorted.map((node) => nodeWeight(node)), 1);
    treemapEl.innerHTML = `<div class="sp-portfolio-columns" role="list">${sorted
      .map((node, index) => {
        const meta = nodeButtonAttrs(node);
        const weight = nodeWeight(node);
        const pct = Math.max(4, Math.round((weight / maxWeight) * 100));
        const reviewClass = meta.needsReview ? ' is-needs-review' : '';
        return `<button type="button" class="sp-portfolio-column sp-portfolio-tile sp-chart-enter-item${meta.drillable ? ' is-drillable' : ''}${meta.type === 'project' ? ' is-project' : ''}${meta.type === 'owner' ? ' is-owner' : ''}${reviewClass}"
          role="listitem"
          style="--i:${index}"
          ${meta.attrs}
        >
          <span class="sp-portfolio-column-value">${escapeHtml(meta.secondary)}</span>
          <span class="sp-portfolio-column-track" aria-hidden="true">
            <span class="sp-portfolio-column-fill" style="--fill:${pct}%;background:${meta.color}"></span>
          </span>
          <span class="sp-portfolio-column-label">${escapeHtml(meta.label)}${meta.needsReview ? ' <em>Review</em>' : ''}</span>
        </button>`;
      })
      .join('')}</div>`;
    playChartEntrance();
  };

  const polarToCartesian = (cx, cy, radius, angleDeg) => {
    const rad = ((angleDeg - 90) * Math.PI) / 180;
    return {
      x: cx + radius * Math.cos(rad),
      y: cy + radius * Math.sin(rad),
    };
  };

  const donutSlicePath = (cx, cy, outerR, innerR, startAngle, endAngle) => {
    const large = endAngle - startAngle > 180 ? 1 : 0;
    const outerStart = polarToCartesian(cx, cy, outerR, endAngle);
    const outerEnd = polarToCartesian(cx, cy, outerR, startAngle);
    const innerStart = polarToCartesian(cx, cy, innerR, startAngle);
    const innerEnd = polarToCartesian(cx, cy, innerR, endAngle);
    return [
      'M', outerStart.x, outerStart.y,
      'A', outerR, outerR, 0, large, 0, outerEnd.x, outerEnd.y,
      'L', innerStart.x, innerStart.y,
      'A', innerR, innerR, 0, large, 1, innerEnd.x, innerEnd.y,
      'Z',
    ].join(' ');
  };

  const renderShareChart = (nodes) => {
    const sorted = sortedChartNodes(nodes);
    const { height: minHeight } = getTreemapSize();
    prepareAltChartShell('is-share-chart', Math.max(minHeight, 420));

    if (!sorted.length) {
      treemapEl.innerHTML = `<p class="sp-size-empty">${emptyChartMessage()}</p>`;
      return;
    }

    const total = sorted.reduce((sum, node) => sum + nodeWeight(node), 0) || 1;
    const cx = 120;
    const cy = 120;
    const outerR = 104;
    const innerR = 58;
    let angle = 0;
    const slices = sorted.map((node) => {
      const weight = nodeWeight(node);
      const sweep = (weight / total) * 360;
      const start = angle;
      const end = angle + Math.max(sweep, weight > 0 ? 0.35 : 0);
      angle = end;
      const meta = nodeButtonAttrs(node);
      const share = weightSharePct(weight, total);
      return { node, meta, weight, share, start, end };
    });

    const paths = slices
      .map(({ meta, start, end }, index) => {
        if (end - start >= 359.9) {
          return `<circle class="sp-portfolio-share-slice sp-portfolio-tile sp-chart-enter-item${meta.drillable ? ' is-drillable' : ''}" style="--i:${index}" cx="${cx}" cy="${cy}" r="${(outerR + innerR) / 2}" fill="none" stroke="${escapeAttr(meta.color)}" stroke-width="${outerR - innerR}" ${meta.attrs}></circle>`;
        }
        return `<path class="sp-portfolio-share-slice sp-portfolio-tile sp-chart-enter-item${meta.drillable ? ' is-drillable' : ''}" style="--i:${index}" d="${donutSlicePath(cx, cy, outerR, innerR, start, end)}" fill="${escapeAttr(meta.color)}" ${meta.attrs}></path>`;
      })
      .join('');

    const legend = slices
      .map(({ meta, share }, index) => {
        const reviewClass = meta.needsReview ? ' is-needs-review' : '';
        return `<button type="button" class="sp-portfolio-share-legend-item sp-portfolio-tile sp-chart-enter-item${meta.drillable ? ' is-drillable' : ''}${meta.type === 'project' ? ' is-project' : ''}${meta.type === 'owner' ? ' is-owner' : ''}${reviewClass}" style="--i:${index}" ${meta.attrs}>
          <span class="sp-portfolio-share-swatch" style="background:${meta.color}" aria-hidden="true"></span>
          <span class="sp-portfolio-share-legend-label">${escapeHtml(meta.label)}${meta.needsReview ? ' <em>Review</em>' : ''}</span>
          <span class="sp-portfolio-share-legend-meta">${share}% · ${escapeHtml(meta.secondary)}</span>
        </button>`;
      })
      .join('');

    treemapEl.innerHTML = `<div class="sp-portfolio-share">
      <div class="sp-portfolio-share-visual" aria-hidden="false">
        <svg class="sp-portfolio-share-svg sp-chart-enter-item" style="--i:0" viewBox="0 0 240 240" role="img" aria-label="Share of total">
          ${paths}
          <circle cx="${cx}" cy="${cy}" r="${innerR - 2}" fill="#fff"></circle>
          <text x="${cx}" y="${cy - 6}" text-anchor="middle" class="sp-portfolio-share-center-value">${sorted.length}</text>
          <text x="${cx}" y="${cy + 14}" text-anchor="middle" class="sp-portfolio-share-center-label">items</text>
        </svg>
      </div>
      <div class="sp-portfolio-share-legend" role="list">${legend}</div>
    </div>`;
    playChartEntrance();
  };

  const renderRankChart = (nodes) => {
    const sorted = sortedChartNodes(nodes);
    const { height: minHeight } = getTreemapSize();
    const chartHeight = Math.max(minHeight, Math.min(720, sorted.length * 44 + 20));
    prepareAltChartShell('is-rank-chart', chartHeight);

    if (!sorted.length) {
      treemapEl.innerHTML = `<p class="sp-size-empty">${emptyChartMessage()}</p>`;
      return;
    }

    const total = sorted.reduce((sum, node) => sum + nodeWeight(node), 0) || 1;
    const maxWeight = Math.max(...sorted.map((node) => nodeWeight(node)), 1);
    const rankByValue = sorted
      .slice()
      .sort((a, b) => nodeWeight(b) - nodeWeight(a))
      .reduce((map, node, index) => {
        map.set(node, index + 1);
        return map;
      }, new Map());
    treemapEl.innerHTML = `<div class="sp-portfolio-rank" role="list">${sorted
      .map((node, index) => {
        const meta = nodeButtonAttrs(node);
        const weight = nodeWeight(node);
        const share = weightSharePct(weight, total);
        const pct = Math.max(3, Math.round((weight / maxWeight) * 100));
        const reviewClass = meta.needsReview ? ' is-needs-review' : '';
        const rank = rankByValue.get(node) || 1;
        return `<button type="button" class="sp-portfolio-rank-row sp-portfolio-tile sp-chart-enter-item${meta.drillable ? ' is-drillable' : ''}${meta.type === 'project' ? ' is-project' : ''}${meta.type === 'owner' ? ' is-owner' : ''}${reviewClass}"
          role="listitem"
          style="--i:${index}"
          ${meta.attrs}
        >
          <span class="sp-portfolio-rank-pos">${rank}</span>
          <span class="sp-portfolio-rank-main">
            <span class="sp-portfolio-rank-label">${escapeHtml(meta.label)}${meta.needsReview ? ' <em>Review</em>' : ''}</span>
            <span class="sp-portfolio-rank-track" aria-hidden="true">
              <span class="sp-portfolio-rank-fill" style="--fill:${pct}%;background:${meta.color}"></span>
            </span>
          </span>
          <span class="sp-portfolio-rank-meta">
            <strong>${share}%</strong>
            <span>${escapeHtml(meta.secondary)}</span>
          </span>
        </button>`;
      })
      .join('')}</div>`;
    playChartEntrance();
  };

  const renderParetoChart = (nodes) => {
    const sorted = sortedChartNodes(nodes);
    const { height: minHeight } = getTreemapSize();
    const chartHeight = Math.max(minHeight, Math.min(760, sorted.length * 42 + 72));
    prepareAltChartShell('is-pareto-chart', chartHeight);

    if (!sorted.length) {
      treemapEl.innerHTML = `<p class="sp-size-empty">${emptyChartMessage()}</p>`;
      return;
    }

    const total = sorted.reduce((sum, node) => sum + nodeWeight(node), 0) || 1;
    const maxWeight = Math.max(...sorted.map((node) => nodeWeight(node)), 1);
    const byDesc = sorted
      .slice()
      .sort((a, b) => nodeWeight(b) - nodeWeight(a));
    let focusRunning = 0;
    let topN = byDesc.length;
    let topShare = 100;
    for (let i = 0; i < byDesc.length; i += 1) {
      focusRunning += nodeWeight(byDesc[i]);
      const cum = weightSharePct(focusRunning, total);
      if (cum >= 80) {
        topN = i + 1;
        topShare = cum;
        break;
      }
    }
    let running = 0;
    const rows = sorted.map((node) => {
      const weight = nodeWeight(node);
      running += weight;
      return {
        node,
        weight,
        cumulative: weightSharePct(running, total),
        pct: Math.max(2, Math.round((weight / maxWeight) * 100)),
      };
    });

    treemapEl.innerHTML = `
      <div class="sp-portfolio-pareto-insight sp-chart-enter-item" style="--i:0">
        Top <strong>${topN}</strong> account for <strong>${topShare}%</strong> of this view
      </div>
      <div class="sp-portfolio-pareto" role="list">${rows
        .map(({ node, cumulative, pct }, index) => {
          const meta = nodeButtonAttrs(node);
          const reviewClass = meta.needsReview ? ' is-needs-review' : '';
          return `<button type="button" class="sp-portfolio-pareto-row sp-portfolio-tile sp-chart-enter-item${meta.drillable ? ' is-drillable' : ''}${meta.type === 'project' ? ' is-project' : ''}${meta.type === 'owner' ? ' is-owner' : ''}${reviewClass}"
            role="listitem"
            style="--i:${index + 1}"
            ${meta.attrs}
          >
            <span class="sp-portfolio-pareto-label">${escapeHtml(meta.label)}${meta.needsReview ? ' <em>Review</em>' : ''}</span>
            <span class="sp-portfolio-pareto-track" aria-hidden="true">
              <span class="sp-portfolio-pareto-fill" style="--fill:${pct}%;background:${meta.color}"></span>
              <span class="sp-portfolio-pareto-cumulative" style="--cum:${Math.min(100, cumulative)}%"></span>
            </span>
            <span class="sp-portfolio-pareto-meta">
              <strong>${cumulative}%</strong>
              <span>${escapeHtml(meta.secondary)}</span>
            </span>
          </button>`;
        })
        .join('')}</div>`;
    playChartEntrance();
  };

  const renderMixChart = (nodes) => {
    const sorted = sortedChartNodes(nodes);
    const { height: minHeight } = getTreemapSize();
    prepareAltChartShell('is-mix-chart', Math.max(minHeight, 360));

    if (!sorted.length) {
      treemapEl.innerHTML = `<p class="sp-size-empty">${emptyChartMessage()}</p>`;
      return;
    }

    const total = sorted.reduce((sum, node) => sum + nodeWeight(node), 0) || 1;
    const segments = sorted.map((node) => {
      const weight = nodeWeight(node);
      const share = weightSharePct(weight, total);
      return { node, weight, share, flex: Math.max(share, 0.6) };
    });

    treemapEl.innerHTML = `
      <div class="sp-portfolio-mix">
        <div class="sp-portfolio-mix-track sp-chart-enter-item" style="--i:0" role="list">${segments
          .map(({ node, share, flex }, index) => {
            const meta = nodeButtonAttrs(node);
            const reviewClass = meta.needsReview ? ' is-needs-review' : '';
            return `<button type="button" class="sp-portfolio-mix-seg sp-portfolio-tile${meta.drillable ? ' is-drillable' : ''}${reviewClass}"
              role="listitem"
              style="flex-grow:${flex};background:${meta.color};--i:${index}"
              ${meta.attrs}
              title="${escapeAttr(`${meta.label} · ${share}% · ${meta.secondary}`)}"
            ><span>${share >= 7 ? `${escapeHtml(meta.label.length > 14 ? `${meta.label.slice(0, 12)}…` : meta.label)}` : ''}</span></button>`;
          })
          .join('')}</div>
        <div class="sp-portfolio-mix-legend" role="list">${segments
          .map(({ node, share }, index) => {
            const meta = nodeButtonAttrs(node);
            const reviewClass = meta.needsReview ? ' is-needs-review' : '';
            return `<button type="button" class="sp-portfolio-mix-legend-item sp-portfolio-tile sp-chart-enter-item${meta.drillable ? ' is-drillable' : ''}${meta.type === 'project' ? ' is-project' : ''}${meta.type === 'owner' ? ' is-owner' : ''}${reviewClass}"
              role="listitem"
              style="--i:${index + 1}"
              ${meta.attrs}
            >
              <span class="sp-portfolio-share-swatch" style="background:${meta.color}" aria-hidden="true"></span>
              <span class="sp-portfolio-mix-legend-label">${escapeHtml(meta.label)}${meta.needsReview ? ' <em>Review</em>' : ''}</span>
              <span class="sp-portfolio-mix-legend-meta">${share}% · ${escapeHtml(meta.secondary)}</span>
            </button>`;
          })
          .join('')}</div>
      </div>`;
    playChartEntrance();
  };

  const renderBubblesChart = (nodes) => {
    const sorted = sortedChartNodes(nodes);
    const { height: minHeight } = getTreemapSize();
    prepareAltChartShell('is-bubbles-chart', Math.max(minHeight, 420));

    if (!sorted.length) {
      treemapEl.innerHTML = `<p class="sp-size-empty">${emptyChartMessage()}</p>`;
      return;
    }

    const maxWeight = Math.max(...sorted.map((node) => nodeWeight(node)), 1);
    const total = sorted.reduce((sum, node) => sum + nodeWeight(node), 0) || 1;
    treemapEl.innerHTML = `<div class="sp-portfolio-bubbles" role="list">${sorted
      .map((node, index) => {
        const meta = nodeButtonAttrs(node);
        const weight = nodeWeight(node);
        const share = weightSharePct(weight, total);
        const scale = Math.sqrt(weight / maxWeight);
        const size = Math.round(64 + scale * 108);
        const reviewClass = meta.needsReview ? ' is-needs-review' : '';
        return `<button type="button" class="sp-portfolio-bubble sp-portfolio-tile sp-chart-enter-item${meta.drillable ? ' is-drillable' : ''}${meta.type === 'project' ? ' is-project' : ''}${meta.type === 'owner' ? ' is-owner' : ''}${reviewClass}"
          role="listitem"
          style="--i:${index};--bubble:${size}px;--bubble-color:${meta.color}"
          ${meta.attrs}
        >
          <span class="sp-portfolio-bubble-share">${share}%</span>
          <span class="sp-portfolio-bubble-label">${escapeHtml(meta.label)}${meta.needsReview ? ' <em>Review</em>' : ''}</span>
          <span class="sp-portfolio-bubble-value">${escapeHtml(meta.secondary)}</span>
        </button>`;
      })
      .join('')}</div>`;
    playChartEntrance();
  };

  const currentChartNodes = () => {
    if (hasActiveProjectFilters()) {
      return buildHeatmapNodesFromProjects(filteredProjectRows());
    }
    return state.data?.nodes || [];
  };

  const renderChart = (nodes) => {
    setChartTypeUi();
    if (state.chartType === 'bar') {
      renderBarChart(nodes);
      return;
    }
    if (state.chartType === 'columns') {
      renderColumnsChart(nodes);
      return;
    }
    if (state.chartType === 'share') {
      renderShareChart(nodes);
      return;
    }
    if (state.chartType === 'rank') {
      renderRankChart(nodes);
      return;
    }
    if (state.chartType === 'pareto') {
      renderParetoChart(nodes);
      return;
    }
    if (state.chartType === 'mix') {
      renderMixChart(nodes);
      return;
    }
    if (state.chartType === 'bubbles') {
      renderBubblesChart(nodes);
      return;
    }
    renderTreemap(nodes);
  };

  const renderBreadcrumb = () => {
    if (!breadcrumbEl) return;
    const crumbs = [
      `<button type="button" class="sp-size-crumb${state.level === 'portfolios' ? ' is-active' : ''}" data-portfolio-level="portfolios"${state.level === 'portfolios' ? ' aria-current="location"' : ''}>All portfolios</button>`,
    ];
    if (state.portfolio && (state.level === 'sub_portfolios' || state.level === 'owners' || state.level === 'projects')) {
      crumbs.push('<span class="sp-size-crumb-sep" aria-hidden="true">›</span>');
      const portfolioActive =
        state.level === 'sub_portfolios' ||
        state.level === 'owners' ||
        (state.level === 'projects' && !state.subPortfolio && !state.ownerKey);
      let portfolioLevel = 'sub_portfolios';
      if (state.mode === 'projects') portfolioLevel = 'projects';
      if (state.mode === 'owners') portfolioLevel = 'owners';
      crumbs.push(
        `<button type="button" class="sp-size-crumb${portfolioActive ? ' is-active' : ''}" data-portfolio-level="${portfolioLevel}" data-portfolio="${escapeAttr(state.portfolio)}"${portfolioActive ? ' aria-current="location"' : ''}>${escapeHtml(state.portfolio)}</button>`
      );
    }
    if (state.ownerKey && state.level === 'projects' && state.mode === 'owners') {
      crumbs.push('<span class="sp-size-crumb-sep" aria-hidden="true">›</span>');
      crumbs.push(
        `<button type="button" class="sp-size-crumb is-active" data-portfolio-level="projects" data-portfolio="${escapeAttr(state.portfolio)}" data-owner-key="${escapeAttr(state.ownerKey)}" data-owner-name="${escapeAttr(state.ownerName || state.ownerKey)}" aria-current="location">${escapeHtml(state.ownerName || state.ownerKey)}</button>`
      );
    }
    if (state.subPortfolio && state.level === 'projects' && state.mode === 'storage') {
      crumbs.push('<span class="sp-size-crumb-sep" aria-hidden="true">›</span>');
      crumbs.push(
        `<button type="button" class="sp-size-crumb is-active" data-portfolio-level="projects" data-portfolio="${escapeAttr(state.portfolio)}" data-sub-portfolio="${escapeAttr(state.subPortfolio)}" aria-current="location">${escapeHtml(state.subPortfolio)}</button>`
      );
    }
    breadcrumbEl.innerHTML = crumbs.join('');
    if (backBtn) backBtn.hidden = state.level === 'portfolios';
    if (scopesRoot) scopesRoot.hidden = state.level !== 'portfolios';
    setModeUi();
  };

  const renderKpis = (kpis) => {
    if (!kpisEl) return;
    if (!kpis) {
      kpisEl.innerHTML = '';
      return;
    }
    let cards;
    if (state.level === 'projects') {
      cards = [
        {
          label: state.ownerKey
            ? 'Owner storage'
            : state.subPortfolio
              ? 'Sub-portfolio storage'
              : 'Portfolio storage',
          value: formatBytes(kpis.total_bytes),
        },
        { label: 'Projects', value: String(kpis.project_count ?? 0) },
        { label: 'Files', value: String(kpis.file_count ?? 0) },
      ];
    } else if (state.level === 'owners') {
      cards = [
        { label: 'Portfolio storage', value: formatBytes(kpis.total_bytes) },
        { label: 'Owners', value: String(kpis.owner_count ?? 0) },
        { label: 'Projects', value: String(kpis.project_count ?? 0) },
        { label: 'Files', value: String(kpis.file_count ?? 0) },
      ];
    } else if (state.level === 'sub_portfolios') {
      cards = [
        { label: 'Portfolio storage', value: formatBytes(kpis.total_bytes) },
        { label: 'Sub-portfolios', value: String(kpis.sub_portfolio_count ?? 0) },
        { label: 'Projects', value: String(kpis.project_count ?? 0) },
        { label: 'Files', value: String(kpis.file_count ?? 0) },
      ];
    } else {
      cards = [
        { label: 'Total storage', value: formatBytes(kpis.total_bytes) },
        { label: 'Portfolios', value: String(kpis.portfolio_count ?? 0) },
        { label: 'Projects', value: String(kpis.project_count ?? 0) },
        { label: 'Files', value: String(kpis.file_count ?? 0) },
        {
          label: 'Largest',
          value: kpis.largest_label ? formatBytes(kpis.largest_bytes) : '—',
          hint: kpis.largest_label || 'No portfolios yet',
        },
      ];
    }
    kpisEl.innerHTML = cards
      .map((card) => {
        const hint = card.hint
          ? `<span class="sp-size-kpi-hint">${escapeHtml(card.hint)}</span>`
          : '';
        return `<div class="sp-size-kpi"><span class="sp-size-kpi-label">${escapeHtml(card.label)}</span><span class="sp-size-kpi-value">${escapeHtml(card.value)}</span>${hint}</div>`;
      })
      .join('');
  };

  const renderCoverage = (coverage, mappingError) => {
    if (!coverageEl) return;
    if (state.level !== 'portfolios' || !coverage) {
      coverageEl.hidden = true;
      coverageEl.innerHTML = '';
      return;
    }
    const mapped = Number(coverage.mapped) || 0;
    const total = Number(coverage.total) || 0;
    const unmapped = Number(coverage.unmapped) || 0;
    const needsReview = Number(coverage.needs_review) || 0;
    const pct = total > 0 ? Math.round((mapped / total) * 100) : 0;
    const warn = unmapped > 0 || needsReview > 0;
    coverageEl.hidden = false;
    coverageEl.innerHTML = `
      <div class="sp-portfolio-coverage-card${warn ? ' is-warn' : ''}">
        <strong>Mapping coverage</strong>
        <span>${pct}% mapped (${mapped} of ${total} projects)</span>
        <span>${unmapped} unmapped · ${needsReview} need review</span>
        ${mappingError ? `<span class="sp-portfolio-coverage-error">${escapeHtml(mappingError)}</span>` : ''}
      </div>
    `;
  };

  const hueFromKey = (value) => {
    const raw = String(value || '');
    let hash = 2166136261;
    for (let i = 0; i < raw.length; i += 1) {
      hash ^= raw.charCodeAt(i);
      hash = Math.imul(hash, 16777619);
    }
    return Math.abs(hash) % 360;
  };

  const hasActiveProjectFilters = () => {
    const f = state.projectFilters || {};
    return !!(
      f.source
      || f.owner
      || f.portfolio
      || f.sub
      || String(state.projectSearch || '').trim()
      || state.projectUniqueOnly
    );
  };

  const filteredProjectRows = () => {
    const query = String(state.projectSearch || '').trim().toLowerCase();
    const filters = state.projectFilters || {};
    let rows = (state.projectMenu || []).filter((row) => {
      if (filters.source && String(row.source_key || '') !== filters.source) return false;
      if (filters.owner && String(row.owner_key || '') !== filters.owner) return false;
      if (filters.portfolio && String(row.portfolio || '') !== filters.portfolio) return false;
      if (filters.sub && String(row.sub_portfolio || '') !== filters.sub) return false;
      if (!query) return true;
      const hay = [
        row.project_name,
        row.owner_name,
        row.portfolio,
        row.sub_portfolio,
        row.source_title,
      ]
        .map((v) => String(v || '').toLowerCase())
        .join(' ');
      return hay.includes(query);
    });

    if (state.projectUniqueOnly) {
      /** @type {Map<string, any>} */
      const byName = new Map();
      rows.forEach((row) => {
        const nameKey = String(row.project_name || row.label || '')
          .trim()
          .toLowerCase()
          .replace(/\s+/g, ' ');
        if (!nameKey) return;
        const prev = byName.get(nameKey);
        if (!prev) {
          byName.set(nameKey, row);
          return;
        }
        if ((Number(row.size_bytes) || 0) > (Number(prev.size_bytes) || 0)) {
          byName.set(nameKey, row);
        }
      });
      rows = Array.from(byName.values());
    }

    return rows;
  };

  const buildHeatmapNodesFromProjects = (rows) => {
    if (state.level === 'projects') {
      return rows.map((row) => {
        const projectName = String(row.project_name || row.label || '');
        const sourceKey = String(row.source_key || '');
        return {
          ...row,
          key: String(row.key || `${sourceKey}\n${projectName}`),
          label: projectName,
          type: 'project',
          project_name: projectName,
          source_key: sourceKey,
          size_bytes: Number(row.size_bytes) || 0,
          file_count: Number(row.file_count) || 0,
          project_count: 1,
          hue: Number.isFinite(Number(row.hue)) ? Number(row.hue) : hueFromKey(`${sourceKey}\n${projectName}`),
        };
      });
    }

    /** @type {Map<string, any>} */
    const buckets = new Map();
    const bucketKey = (row) => {
      if (state.level === 'owners') return String(row.owner_key || '_unassigned');
      if (state.level === 'sub_portfolios') return String(row.sub_portfolio || '—');
      return String(row.portfolio || '—');
    };
    const bucketLabel = (row, key) => {
      if (state.level === 'owners') return String(row.owner_name || key);
      return key;
    };
    const bucketType = () => {
      if (state.level === 'owners') return 'owner';
      if (state.level === 'sub_portfolios') return 'sub_portfolio';
      return 'portfolio';
    };

    rows.forEach((row) => {
      const key = bucketKey(row);
      if (!buckets.has(key)) {
        const type = bucketType();
        const label = bucketLabel(row, key);
        buckets.set(key, {
          key,
          label,
          type,
          owner_key: type === 'owner' ? key : String(row.owner_key || ''),
          owner_name: type === 'owner' ? label : String(row.owner_name || ''),
          portfolio: String(row.portfolio || state.portfolio || ''),
          sub_portfolio: type === 'sub_portfolio' ? label : String(row.sub_portfolio || ''),
          size_bytes: 0,
          file_count: 0,
          project_count: 0,
          owner_count: 0,
          _owners: new Set(),
          hue: hueFromKey(`${type}:${key}`),
        });
      }
      const node = buckets.get(key);
      node.size_bytes += Number(row.size_bytes) || 0;
      node.file_count += Number(row.file_count) || 0;
      node.project_count += 1;
      if (row.owner_key) node._owners.add(String(row.owner_key));
    });

    return Array.from(buckets.values()).map((node) => {
      node.owner_count = node._owners.size;
      delete node._owners;
      return node;
    });
  };

  const filteredKpisFromProjects = (rows) => {
    let totalBytes = 0;
    let fileCount = 0;
    rows.forEach((row) => {
      totalBytes += Number(row.size_bytes) || 0;
      fileCount += Number(row.file_count) || 0;
    });
    const base = {
      total_bytes: totalBytes,
      file_count: fileCount,
      project_count: rows.length,
    };
    if (state.level === 'owners') {
      const owners = new Set(rows.map((r) => String(r.owner_key || '')));
      return { ...base, owner_count: owners.size };
    }
    if (state.level === 'sub_portfolios') {
      const subs = new Set(rows.map((r) => String(r.sub_portfolio || '')));
      return { ...base, sub_portfolio_count: subs.size };
    }
    if (state.level === 'portfolios') {
      const portfolios = new Set(rows.map((r) => String(r.portfolio || '')));
      let largestBytes = 0;
      let largestLabel = '';
      const byPortfolio = new Map();
      rows.forEach((row) => {
        const key = String(row.portfolio || '');
        const next = (byPortfolio.get(key) || 0) + (Number(row.size_bytes) || 0);
        byPortfolio.set(key, next);
        if (next > largestBytes) {
          largestBytes = next;
          largestLabel = key;
        }
      });
      return {
        ...base,
        portfolio_count: portfolios.size,
        largest_bytes: largestBytes,
        largest_label: largestLabel,
      };
    }
    return base;
  };

  const sortedLargeFiles = () => {
    const { key, direction } = state.fileSort;
    const dir = direction === 'asc' ? 1 : -1;
    const filteredProjects = filteredProjectRows();
    const filtersActive = hasActiveProjectFilters();
    const allowedProjects = filtersActive
      ? new Set(
          filteredProjects.map((row) =>
            `${String(row.source_key || '')}\n${String(row.project_name || row.label || '')}`.toLowerCase()
          )
        )
      : null;
    return (state.largeFiles || [])
      .filter((file) => {
        if (!allowedProjects) return true;
        const keyName = `${String(file.source_key || '')}\n${String(file.project_name || '')}`.toLowerCase();
        return allowedProjects.has(keyName);
      })
      .slice()
      .sort((a, b) => {
        if (key === 'size') return ((Number(a.size_bytes) || 0) - (Number(b.size_bytes) || 0)) * dir;
        if (key === 'modified') {
          return String(a.last_modified || '').localeCompare(String(b.last_modified || '')) * dir;
        }
        if (key === 'portfolio') {
          return String(a.portfolio || '').localeCompare(String(b.portfolio || ''), undefined, { sensitivity: 'base' }) * dir;
        }
        if (key === 'project') {
          return String(a.project_name || '').localeCompare(String(b.project_name || ''), undefined, { sensitivity: 'base' }) * dir;
        }
        return String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' }) * dir;
      });
  };

  const updateFileSortHeaders = () => {
    if (!filesTable) return;
    filesTable.querySelectorAll('th[data-portfolio-file-sort]').forEach((th) => {
      const key = th.getAttribute('data-portfolio-file-sort');
      th.classList.remove('is-sorted-asc', 'is-sorted-desc');
      th.removeAttribute('aria-sort');
      if (key === state.fileSort.key) {
        th.classList.add(state.fileSort.direction === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
        th.setAttribute('aria-sort', state.fileSort.direction === 'asc' ? 'ascending' : 'descending');
      } else {
        th.setAttribute('aria-sort', 'none');
      }
    });
  };

  const renderFiles = () => {
    if (!filesBody) return;
    const files = sortedLargeFiles();
    updateFileSortHeaders();
    if (filesHelp) {
      if (state.level === 'projects' && state.ownerKey) {
        filesHelp.textContent = `Largest files for ${state.ownerName || 'this owner'} in ${state.portfolio || 'this portfolio'}`;
      } else if (state.level === 'projects' && state.subPortfolio) {
        filesHelp.textContent = `Largest files in ${state.subPortfolio}`;
      } else if (state.level === 'projects' || state.level === 'owners') {
        filesHelp.textContent = `Largest files in ${state.portfolio || 'this portfolio'}`;
      } else if (state.level === 'sub_portfolios') {
        filesHelp.textContent = `Largest files in ${state.portfolio || 'this portfolio'}`;
      } else {
        filesHelp.textContent = 'Top files across selected architecture catalogs';
      }
    }
    if (!files.length) {
      filesBody.innerHTML = '<tr><td colspan="5" class="sp-size-empty">No files in this view.</td></tr>';
      return;
    }
    filesBody.innerHTML = files
      .map((file) => {
        const name = String(file.name || '');
        const project = String(file.project_name || '');
        const portfolio = String(file.portfolio || '');
        const sourceKey = String(file.source_key || '');
        const url = String(file.web_url || '');
        const nameHtml = url
          ? `<a href="${escapeAttr(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(name)}</a>`
          : escapeHtml(name);
        const projectBtn = project
          ? `<button type="button" class="sp-portfolio-project-link" data-source-key="${escapeAttr(sourceKey)}" data-project-name="${escapeAttr(project)}">${escapeHtml(project)}</button>`
          : '—';
        return `<tr>
          <td>${nameHtml}</td>
          <td>${escapeHtml(portfolio || '—')}</td>
          <td>${projectBtn}</td>
          <td>${escapeHtml(formatBytes(file.size_bytes))}</td>
          <td>${escapeHtml(formatModified(file.last_modified))}</td>
        </tr>`;
      })
      .join('');
  };

  const sortedProjectMenu = () => {
    const { key, direction } = state.projectSort;
    const dir = direction === 'asc' ? 1 : -1;
    return filteredProjectRows()
      .slice()
      .sort((a, b) => {
        if (key === 'size') return ((Number(a.size_bytes) || 0) - (Number(b.size_bytes) || 0)) * dir;
        if (key === 'files') return ((Number(a.file_count) || 0) - (Number(b.file_count) || 0)) * dir;
        const left =
          key === 'owner'
            ? a.owner_name
            : key === 'portfolio'
              ? a.portfolio
              : key === 'sub'
                ? a.sub_portfolio
                : a.project_name || a.label;
        const right =
          key === 'owner'
            ? b.owner_name
            : key === 'portfolio'
              ? b.portfolio
              : key === 'sub'
                ? b.sub_portfolio
                : b.project_name || b.label;
        return String(left || '').localeCompare(String(right || ''), undefined, { sensitivity: 'base' }) * dir;
      });
  };

  const uniqueSortedValues = (rows, getter) => {
    const map = new Map();
    rows.forEach((row) => {
      const value = String(getter(row) || '').trim();
      if (!value) return;
      if (!map.has(value)) map.set(value, value);
    });
    return Array.from(map.keys()).sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }));
  };

  const fillSelectOptions = (select, values, allLabel, selected, labelForValue = null) => {
    if (!select) return;
    const current = selected || '';
    const options = [`<option value="">${escapeHtml(allLabel)}</option>`];
    values.forEach((value) => {
      const label = labelForValue ? labelForValue(value) : value;
      options.push(`<option value="${escapeAttr(value)}">${escapeHtml(label)}</option>`);
    });
    select.innerHTML = options.join('');
    if (current && values.includes(current)) {
      select.value = current;
    } else {
      select.value = '';
      if (selected && selected !== select.value) {
        // Selected value no longer available for this cascade.
        return false;
      }
    }
    return true;
  };

  const syncProjectFilterControls = () => {
    const rows = state.projectMenu || [];
    const filters = state.projectFilters;
    const sourceSelect = document.getElementById('sp-portfolio-filter-source');
    const ownerSelect = document.getElementById('sp-portfolio-filter-owner');
    const portfolioSelect = document.getElementById('sp-portfolio-filter-portfolio');
    const subSelect = document.getElementById('sp-portfolio-filter-sub');
    const clearBtn = document.getElementById('sp-portfolio-filters-clear');

    const sourceLabels = new Map();
    rows.forEach((row) => {
      const key = String(row.source_key || '');
      if (!key || sourceLabels.has(key)) return;
      sourceLabels.set(key, String(row.source_title || key));
    });
    fillSelectOptions(
      sourceSelect,
      Array.from(sourceLabels.keys()).sort((a, b) =>
        String(sourceLabels.get(a) || a).localeCompare(String(sourceLabels.get(b) || b), undefined, { sensitivity: 'base' })
      ),
      'All catalogs',
      filters.source,
      (value) => sourceLabels.get(value) || value
    );

    const ownerLabels = new Map();
    rows.forEach((row) => {
      const key = String(row.owner_key || '');
      if (!key || ownerLabels.has(key)) return;
      ownerLabels.set(key, String(row.owner_name || key));
    });
    fillSelectOptions(
      ownerSelect,
      Array.from(ownerLabels.keys()).sort((a, b) =>
        String(ownerLabels.get(a) || a).localeCompare(String(ownerLabels.get(b) || b), undefined, { sensitivity: 'base' })
      ),
      'All owners',
      filters.owner,
      (value) => ownerLabels.get(value) || value
    );

    fillSelectOptions(
      portfolioSelect,
      uniqueSortedValues(rows, (row) => row.portfolio),
      'All portfolios',
      filters.portfolio
    );

    const subSourceRows = filters.portfolio
      ? rows.filter((row) => String(row.portfolio || '') === filters.portfolio)
      : rows;
    const subOk = fillSelectOptions(
      subSelect,
      uniqueSortedValues(subSourceRows, (row) => row.sub_portfolio),
      'All sub-portfolios',
      filters.sub
    );
    if (!subOk) {
      filters.sub = '';
    }

    if (clearBtn) clearBtn.hidden = !hasActiveProjectFilters();
    renderChartFilterSummary();
  };

  const chartFilterLabel = (key, value) => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (key === 'source') {
      const row = (state.projectMenu || []).find((item) => String(item.source_key || '') === raw);
      return String(row?.source_title || raw);
    }
    if (key === 'owner') {
      const row = (state.projectMenu || []).find((item) => String(item.owner_key || '') === raw);
      return String(row?.owner_name || raw);
    }
    return raw;
  };

  const renderChartFilterSummary = () => {
    if (!chartFiltersEl) return;
    if (!hasActiveProjectFilters()) {
      chartFiltersEl.hidden = true;
      chartFiltersEl.innerHTML = '';
      return;
    }

    const filters = state.projectFilters || {};
    const chips = [];
    if (filters.source) {
      chips.push({ key: 'source', label: 'Catalog', value: chartFilterLabel('source', filters.source) });
    }
    if (filters.owner) {
      chips.push({ key: 'owner', label: 'Owner', value: chartFilterLabel('owner', filters.owner) });
    }
    if (filters.portfolio) {
      chips.push({ key: 'portfolio', label: 'Portfolio', value: chartFilterLabel('portfolio', filters.portfolio) });
    }
    if (filters.sub) {
      chips.push({ key: 'sub', label: 'Sub-portfolio', value: chartFilterLabel('sub', filters.sub) });
    }
    const search = String(state.projectSearch || '').trim();
    if (search) {
      chips.push({ key: 'search', label: 'Search', value: search });
    }
    if (state.projectUniqueOnly) {
      chips.push({ key: 'unique', label: 'Mode', value: 'Unique projects only' });
    }

    const matched = filteredProjectRows().length;
    const total = (state.projectMenu || []).length;
    chartFiltersEl.hidden = false;
    chartFiltersEl.innerHTML = `
      <div class="sp-portfolio-chart-filters-head">
        <span class="sp-portfolio-chart-filters-title">Chart filters</span>
        <span class="sp-portfolio-chart-filters-count">${matched} of ${total} project${total === 1 ? '' : 's'}</span>
      </div>
      <div class="sp-portfolio-chart-filters-chips" role="list">
        ${chips
          .map(
            (chip) => `<span class="sp-portfolio-chart-filter-chip" role="listitem">
              <span class="sp-portfolio-chart-filter-chip-label">${escapeHtml(chip.label)}</span>
              <strong>${escapeHtml(chip.value)}</strong>
            </span>`
          )
          .join('')}
      </div>
      <button type="button" class="button ghost sp-portfolio-chart-filters-clear" id="sp-portfolio-chart-filters-clear">Clear filters</button>
    `;
  };

  const applyProjectFiltersAndRedraw = () => {
    syncProjectFilterControls();
    renderProjectMenu();
    renderFiles();
    const rows = filteredProjectRows();
    if (hasActiveProjectFilters()) {
      renderKpis(filteredKpisFromProjects(rows));
      renderChart(buildHeatmapNodesFromProjects(rows));
    } else if (state.data?.ok !== false) {
      renderKpis(state.data?.kpis || null);
      renderChart(state.data?.nodes || []);
    }
  };

  const updateProjectSortHeaders = () => {
    if (!projectTable) return;
    projectTable.querySelectorAll('th[data-portfolio-project-sort]').forEach((th) => {
      const key = th.getAttribute('data-portfolio-project-sort');
      th.classList.remove('is-sorted-asc', 'is-sorted-desc');
      th.removeAttribute('aria-sort');
      if (key === state.projectSort.key) {
        th.classList.add(state.projectSort.direction === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
        th.setAttribute('aria-sort', state.projectSort.direction === 'asc' ? 'ascending' : 'descending');
      } else {
        th.setAttribute('aria-sort', 'none');
      }
    });
  };

  const renderProjectMenu = () => {
    if (!projectBody) return;
    syncProjectFilterControls();
    const rows = sortedProjectMenu();
    updateProjectSortHeaders();
    if (projectCountEl) projectCountEl.textContent = String(rows.length);
    if (projectHelp) {
      if (state.level === 'projects' && state.ownerKey) {
        projectHelp.textContent = `Projects owned by ${state.ownerName || 'this owner'} in ${state.portfolio || 'this portfolio'}.`;
      } else if (state.level === 'projects' && state.subPortfolio) {
        projectHelp.textContent = `Projects in ${state.subPortfolio}, with folder owner.`;
      } else if (state.level === 'projects' || state.level === 'sub_portfolios' || state.level === 'owners') {
        projectHelp.textContent = `Projects in ${state.portfolio || 'this portfolio'}, with folder owner.`;
      } else {
        projectHelp.textContent = 'All mapped projects in the selected catalogs, with folder owner.';
      }
      if (hasActiveProjectFilters()) {
        projectHelp.textContent += ' Chart follows the filters shown above.';
      }
    }
    if (!rows.length) {
      projectBody.innerHTML = '<tr><td colspan="6" class="sp-size-empty">No projects match this view.</td></tr>';
      return;
    }
    projectBody.innerHTML = rows
      .map((row) => {
        const projectName = String(row.project_name || row.label || '');
        const sourceKey = String(row.source_key || '');
        const ownerName = String(row.owner_name || '—');
        const ownerKey = String(row.owner_key || '');
        const sourceTitle = String(row.source_title || '');
        const editBtn = state.canEditPortfolio
          ? `<button type="button" class="sp-portfolio-map-edit" data-project-name="${escapeAttr(projectName)}" data-source-key="${escapeAttr(sourceKey)}" data-portfolio="${escapeAttr(String(row.portfolio || ''))}" data-sub-portfolio="${escapeAttr(String(row.sub_portfolio || ''))}" data-confidence="${escapeAttr(String(row.confidence || 'Medium'))}" data-note="${escapeAttr(String(row.note || ''))}" title="Reassign portfolio" aria-label="Reassign portfolio for ${escapeAttr(projectName)}">✎</button>`
          : '';
        const projectBtn = `<div class="sp-project-submenu-project-cell"><button type="button" class="sp-project-submenu-project-link" data-source-key="${escapeAttr(sourceKey)}" data-project-name="${escapeAttr(projectName)}">${escapeHtml(projectName)}</button>${editBtn}${sourceTitle ? `<span class="sp-project-submenu-meta">${escapeHtml(sourceTitle)}</span>` : ''}</div>`;
        const ownerBtn = ownerKey
          ? `<button type="button" class="sp-project-submenu-owner-link" data-owner-key="${escapeAttr(ownerKey)}" data-owner-name="${escapeAttr(ownerName)}">${escapeHtml(ownerName)}</button>`
          : escapeHtml(ownerName);
        return `<tr>
          <td>${projectBtn}</td>
          <td>${ownerBtn}</td>
          <td>${escapeHtml(row.portfolio || '—')}</td>
          <td>${escapeHtml(row.sub_portfolio || '—')}</td>
          <td>${escapeHtml(formatBytes(row.size_bytes))}</td>
          <td>${escapeHtml(String(row.file_count ?? 0))}</td>
        </tr>`;
      })
      .join('');
  };

  const csrfToken = () =>
    root.getAttribute('data-csrf')
    || document.getElementById('sharepoint-search')?.getAttribute('data-csrf')
    || document.getElementById('sharepoint-sources')?.getAttribute('data-csrf')
    || '';

  const mapDialog = document.getElementById('sp-portfolio-map-dialog');
  const mapForm = document.getElementById('sp-portfolio-map-form');
  const mapProject = document.getElementById('sp-portfolio-map-project');
  const mapPortfolio = document.getElementById('sp-portfolio-map-portfolio');
  const mapSub = document.getElementById('sp-portfolio-map-sub');
  const mapPortfolioNewWrap = document.getElementById('sp-portfolio-map-portfolio-new-wrap');
  const mapSubNewWrap = document.getElementById('sp-portfolio-map-sub-new-wrap');
  const mapPortfolioCustom = document.getElementById('sp-portfolio-map-portfolio-custom');
  const mapSubCustom = document.getElementById('sp-portfolio-map-sub-custom');
  const mapConfidence = document.getElementById('sp-portfolio-map-confidence');
  const mapNote = document.getElementById('sp-portfolio-map-note');
  const mapError = document.getElementById('sp-portfolio-map-error');
  const mapSave = document.getElementById('sp-portfolio-map-save');
  const mapSubEl = document.getElementById('sp-portfolio-map-dialog-sub');
  const NEW_PORTFOLIO_VALUE = '__new_portfolio__';
  const NEW_SUB_VALUE = '__new_sub__';

  const setMapError = (message) => {
    if (!mapError) return;
    if (!message) {
      mapError.hidden = true;
      mapError.textContent = '';
      return;
    }
    mapError.hidden = false;
    mapError.textContent = message;
  };

  const ensureOption = (select, value, label = value) => {
    if (!select || !value) return;
    const exists = Array.from(select.options).some((opt) => opt.value === value);
    if (!exists) {
      const opt = document.createElement('option');
      opt.value = value;
      opt.textContent = label;
      select.appendChild(opt);
    }
  };

  const fillPortfolioSelect = (selected = '') => {
    if (!mapPortfolio) return;
    const options = state.mappingOptions || [];
    mapPortfolio.innerHTML = [
      ...options.map((row) => `<option value="${escapeAttr(row.portfolio)}">${escapeHtml(row.portfolio)}</option>`),
      `<option value="${NEW_PORTFOLIO_VALUE}">＋ Add new portfolio…</option>`,
    ].join('');
    if (selected && selected !== NEW_PORTFOLIO_VALUE) {
      ensureOption(mapPortfolio, selected);
      // Keep "Add new" at the end after ensureOption.
      const addOpt = Array.from(mapPortfolio.options).find((opt) => opt.value === NEW_PORTFOLIO_VALUE);
      if (addOpt) mapPortfolio.appendChild(addOpt);
      mapPortfolio.value = selected;
    } else if (mapPortfolio.options.length) {
      mapPortfolio.selectedIndex = 0;
    }
  };

  const fillSubSelect = (portfolio, selected = '') => {
    if (!mapSub) return;
    const creatingPortfolio = portfolio === NEW_PORTFOLIO_VALUE;
    if (creatingPortfolio) {
      mapSub.innerHTML = `<option value="${NEW_SUB_VALUE}">＋ Add new sub-portfolio…</option>`;
      mapSub.value = NEW_SUB_VALUE;
      mapSub.disabled = true;
      return;
    }
    mapSub.disabled = false;
    const match = (state.mappingOptions || []).find(
      (row) => String(row.portfolio || '') === String(portfolio || '')
    );
    const subs = match?.sub_portfolios || [];
    mapSub.innerHTML = [
      ...subs.map((sub) => `<option value="${escapeAttr(sub)}">${escapeHtml(sub)}</option>`),
      `<option value="${NEW_SUB_VALUE}">＋ Add new sub-portfolio…</option>`,
    ].join('');
    if (selected && selected !== NEW_SUB_VALUE) {
      ensureOption(mapSub, selected);
      const addOpt = Array.from(mapSub.options).find((opt) => opt.value === NEW_SUB_VALUE);
      if (addOpt) mapSub.appendChild(addOpt);
      mapSub.value = selected;
    } else if (mapSub.options.length) {
      mapSub.selectedIndex = 0;
    }
  };

  const syncNewFieldsUi = () => {
    const newPortfolio = mapPortfolio?.value === NEW_PORTFOLIO_VALUE;
    const newSub = newPortfolio || mapSub?.value === NEW_SUB_VALUE;
    if (mapPortfolioNewWrap) mapPortfolioNewWrap.hidden = !newPortfolio;
    if (mapSubNewWrap) mapSubNewWrap.hidden = !newSub;
    // When creating a brand-new portfolio, the sub dropdown is only the "new" sentinel — hide it.
    const subSelectWrap = mapSub?.closest('.sp-portfolio-map-field');
    if (subSelectWrap) subSelectWrap.hidden = !!newPortfolio;
    if (mapPortfolioCustom) {
      mapPortfolioCustom.required = newPortfolio;
      if (!newPortfolio) mapPortfolioCustom.value = '';
    }
    if (mapSubCustom) {
      mapSubCustom.required = newSub;
      if (!newSub) mapSubCustom.value = '';
    }
  };

  const loadMappingOptions = async (projectName = '') => {
    if (state.mappingOptions && !projectName) {
      return { options: state.mappingOptions, mapping: null };
    }
    if (state.mappingOptionsLoading) {
      return state.mappingOptionsLoading;
    }
    state.mappingOptionsLoading = (async () => {
      const qs = new URLSearchParams({ action: 'portfolio_mapping_options' });
      if (projectName) qs.set('project_name', projectName);
      const response = await fetch(`sharepoint.php?${qs.toString()}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok || data.ok === false) {
        throw new Error(data.error || `Failed to load portfolio options (${response.status})`);
      }
      state.mappingOptions = Array.isArray(data.options) ? data.options : [];
      if (data.can_edit === false) {
        state.canEditPortfolio = false;
      }
      return data;
    })();
    try {
      return await state.mappingOptionsLoading;
    } finally {
      state.mappingOptionsLoading = null;
    }
  };

  const openMappingEditor = async (row = {}) => {
    if (!state.canEditPortfolio || !mapDialog || typeof mapDialog.showModal !== 'function') return;
    const projectName = String(row.project_name || '').trim();
    if (!projectName) return;
    setMapError('');
    if (mapSave) mapSave.disabled = true;
    if (mapProject) mapProject.value = projectName;
    if (mapSubEl) {
      mapSubEl.textContent = row.source_title
        ? `${row.source_title} · update approximate portfolio mapping`
        : 'Update the approximate portfolio for this project.';
    }
    try {
      const data = await loadMappingOptions(projectName);
      const mapping = data.mapping || {
        portfolio: row.portfolio || '',
        sub_portfolio: row.sub_portfolio || '',
        confidence: row.confidence || 'Medium',
        note: row.note || '',
      };
      fillPortfolioSelect(mapping.portfolio || '');
      fillSubSelect(mapPortfolio?.value || mapping.portfolio || '', mapping.sub_portfolio || '');
      if (mapConfidence) mapConfidence.value = mapping.confidence || 'Medium';
      if (mapNote) mapNote.value = mapping.note || '';
      if (mapPortfolioCustom) mapPortfolioCustom.value = '';
      if (mapSubCustom) mapSubCustom.value = '';
      syncNewFieldsUi();
      mapDialog.showModal();
      window.setTimeout(() => {
        if (mapPortfolio?.value === NEW_PORTFOLIO_VALUE) mapPortfolioCustom?.focus();
        else mapPortfolio?.focus();
      }, 40);
    } catch (err) {
      setMapError(err?.message || 'Could not open mapping editor.');
      if (mapDialog.open) mapDialog.close();
      window.alert(err?.message || 'Could not open mapping editor.');
    } finally {
      if (mapSave) mapSave.disabled = false;
    }
  };

  const saveMapping = async () => {
    if (!state.canEditPortfolio) return;
    const projectName = String(mapProject?.value || '').trim();
    const portfolioChoice = String(mapPortfolio?.value || '').trim();
    const subChoice = String(mapSub?.value || '').trim();
    const portfolio = portfolioChoice === NEW_PORTFOLIO_VALUE
      ? String(mapPortfolioCustom?.value || '').trim()
      : portfolioChoice;
    const subPortfolio = (portfolioChoice === NEW_PORTFOLIO_VALUE || subChoice === NEW_SUB_VALUE)
      ? String(mapSubCustom?.value || '').trim()
      : subChoice;
    const confidence = String(mapConfidence?.value || 'Medium').trim() || 'Medium';
    const note = String(mapNote?.value || '').trim();
    if (!projectName || !portfolio || !subPortfolio) {
      setMapError(
        portfolioChoice === NEW_PORTFOLIO_VALUE
          ? 'Enter a new portfolio name and sub-portfolio.'
          : subChoice === NEW_SUB_VALUE
            ? 'Enter a new sub-portfolio name.'
            : 'Project, portfolio, and sub-portfolio are required.'
      );
      return;
    }
    setMapError('');
    if (mapSave) {
      mapSave.disabled = true;
      mapSave.textContent = 'Saving…';
    }
    try {
      const body = new URLSearchParams();
      body.set('csrf_token', csrfToken());
      body.set('action', 'save_portfolio_mapping');
      body.set('ajax', '1');
      body.set('project_name', projectName);
      body.set('portfolio', portfolio);
      body.set('sub_portfolio', subPortfolio);
      body.set('confidence', confidence);
      body.set('note', note);
      const response = await fetch('sharepoint.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: body.toString(),
      });
      const data = await response.json();
      if (!response.ok || data.ok === false) {
        throw new Error(data.error || `Save failed (${response.status})`);
      }
      if (Array.isArray(data.options)) {
        state.mappingOptions = data.options;
      }
      if (mapDialog?.open) mapDialog.close();
      state.loaded = false;
      state.data = null;
      await load(true);
    } catch (err) {
      setMapError(err?.message || 'Could not save portfolio mapping.');
    } finally {
      if (mapSave) {
        mapSave.disabled = false;
        mapSave.textContent = 'Save mapping';
      }
    }
  };

  const switchToOwnerTab = (ownerKey, ownerName) => {
    if (!ownerKey) return;
    try {
      localStorage.setItem(
        'riskregister_sp_owner_storage_navigation',
        JSON.stringify({
          level: 'owner',
          ownerKey: String(ownerKey).slice(0, 300),
          ownerName: String(ownerName || ownerKey).slice(0, 300),
        })
      );
    } catch (e) { /* ignore */ }
    const tabBtn = root.querySelector('.sp-size-tab[data-tab="owners"]');
    if (tabBtn) {
      tabBtn.click();
      window.setTimeout(() => {
        window.RiskRegisterOwnerStorage?.load?.(true);
      }, 40);
      return;
    }
    const url = new URL('sharepoint.php', window.location.href);
    url.searchParams.set('view', 'heatmap');
    url.searchParams.set('tab', 'owners');
    window.location.href = url.toString();
  };

  const render = () => {
    renderBreadcrumb();
    if (state.loading && !state.data) {
      treemapEl.innerHTML = '<p class="sp-size-empty">Loading portfolio storage…</p>';
      if (kpisEl) kpisEl.innerHTML = '';
      if (coverageEl) {
        coverageEl.hidden = true;
        coverageEl.innerHTML = '';
      }
      if (filesBody) {
        filesBody.innerHTML = '<tr><td colspan="5" class="sp-size-empty">Loading…</td></tr>';
      }
      if (projectBody) {
        projectBody.innerHTML = '<tr><td colspan="6" class="sp-size-empty">Loading…</td></tr>';
      }
      return;
    }
    if (state.data?.ok === false) {
      treemapEl.innerHTML = `<p class="sp-size-empty">${escapeHtml(state.data.error || 'Failed to load portfolio storage.')}</p>`;
      renderKpis(null);
      renderCoverage(null);
      if (filesBody) {
        filesBody.innerHTML = '<tr><td colspan="5" class="sp-size-empty">Unavailable.</td></tr>';
      }
      if (projectBody) {
        projectBody.innerHTML = '<tr><td colspan="6" class="sp-size-empty">Unavailable.</td></tr>';
      }
      return;
    }
    renderCoverage(state.data?.coverage || null, state.data?.mapping_error || '');
    state.largeFiles = state.data?.large_files || [];
    state.projectMenu = state.data?.project_menu || [];
    const filteredRows = filteredProjectRows();
    if (hasActiveProjectFilters()) {
      renderKpis(filteredKpisFromProjects(filteredRows));
      renderChart(buildHeatmapNodesFromProjects(filteredRows));
    } else {
      renderKpis(state.data?.kpis || null);
      renderChart(state.data?.nodes || []);
    }
    renderFiles();
    renderProjectMenu();
  };

  const load = async (force = false) => {
    if (state.loading) return;
    if (state.loaded && !force && state.data) {
      render();
      return;
    }
    state.loading = true;
    render();
    try {
      const sources = readSelectedSources();
      const params = {
        sources: sources.join(',') || 'all',
        level: state.level,
      };
      if (state.portfolio) params.portfolio = state.portfolio;
      if (state.subPortfolio) params.sub_portfolio = state.subPortfolio;
      if (state.ownerKey) params.owner = state.ownerKey;

      const response = await fetch(apiUrl(params), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok || data.ok === false) {
        state.data = { ok: false, error: data.error || `Request failed (${response.status})` };
      } else {
        state.data = data;
        state.loaded = true;
      }
    } catch (err) {
      state.data = { ok: false, error: err?.message || 'Network error loading portfolio storage.' };
    } finally {
      state.loading = false;
      render();
    }
  };

  const resetProjectFilters = () => {
    state.projectFilters = { source: '', owner: '', portfolio: '', sub: '' };
    state.projectSearch = '';
    state.projectUniqueOnly = false;
    if (projectSearch) projectSearch.value = '';
    if (projectUniqueToggle) projectUniqueToggle.checked = false;
  };

  const showPortfolios = () => {
    state.level = 'portfolios';
    state.portfolio = '';
    state.subPortfolio = '';
    state.ownerKey = '';
    state.ownerName = '';
    resetProjectFilters();
    state.loaded = false;
    state.data = null;
    saveNavigation();
    load(true);
  };

  const showSubPortfolios = (portfolio) => {
    state.level = 'sub_portfolios';
    state.portfolio = portfolio;
    state.subPortfolio = '';
    state.ownerKey = '';
    state.ownerName = '';
    resetProjectFilters();
    state.loaded = false;
    state.data = null;
    saveNavigation();
    load(true);
  };

  const showOwners = (portfolio) => {
    state.level = 'owners';
    state.portfolio = portfolio;
    state.subPortfolio = '';
    state.ownerKey = '';
    state.ownerName = '';
    resetProjectFilters();
    state.loaded = false;
    state.data = null;
    saveNavigation();
    load(true);
  };

  const showProjects = (portfolio, subPortfolio = '', ownerKey = '', ownerName = '') => {
    state.level = 'projects';
    state.portfolio = portfolio;
    state.subPortfolio = subPortfolio || '';
    state.ownerKey = ownerKey || '';
    state.ownerName = ownerName || '';
    resetProjectFilters();
    state.loaded = false;
    state.data = null;
    saveNavigation();
    load(true);
  };

  const goBack = () => {
    if (state.level === 'projects') {
      if (state.mode === 'owners') {
        showOwners(state.portfolio);
        return;
      }
      if (state.mode === 'projects' || !state.subPortfolio) {
        showPortfolios();
        return;
      }
      showSubPortfolios(state.portfolio);
      return;
    }
    if (state.level === 'owners' || state.level === 'sub_portfolios') {
      showPortfolios();
    }
  };

  const setMode = (mode) => {
    const next = normalizeMode(mode);
    if (next === state.mode) return;
    state.mode = next;
    try {
      localStorage.setItem(MODE_KEY, next);
    } catch (e) { /* ignore */ }
    // Keep current portfolio when switching modes; jump to the matching drill path.
    if (state.portfolio) {
      if (next === 'projects') {
        showProjects(state.portfolio, '');
      } else if (next === 'owners') {
        showOwners(state.portfolio);
      } else if (state.subPortfolio) {
        showProjects(state.portfolio, state.subPortfolio);
      } else {
        showSubPortfolios(state.portfolio);
      }
      return;
    }
    setModeUi();
    if (state.loaded && state.data?.nodes) {
      renderChart(currentChartNodes());
    }
    saveNavigation();
  };

  const setChartType = (chartType) => {
    const next = normalizeChartType(chartType);
    if (next === state.chartType) return;
    state.chartType = next;
    try {
      localStorage.setItem(CHART_KEY, next);
    } catch (e) { /* ignore */ }
    setChartTypeUi();
    if (state.loaded && state.data) {
      renderChart(currentChartNodes());
    }
  };

  const setChartSort = (sortDir) => {
    const next = normalizeChartSort(sortDir);
    if (next === state.chartSort) return;
    state.chartSort = next;
    try {
      localStorage.setItem(CHART_SORT_KEY, next);
    } catch (e) { /* ignore */ }
    setChartTypeUi();
    if (state.loaded && state.data && SORTABLE_CHART_TYPES.has(state.chartType)) {
      renderChart(currentChartNodes());
    }
  };

  treemapEl.addEventListener('click', (event) => {
    const tile = event.target.closest('.sp-portfolio-tile');
    if (!tile || !treemapEl.contains(tile)) return;
    const type = tile.getAttribute('data-type') || '';
    if (type === 'portfolio') {
      const portfolio = tile.getAttribute('data-portfolio') || tile.getAttribute('data-label') || '';
      if (!portfolio) return;
      if (state.mode === 'projects') {
        showProjects(portfolio, '');
      } else if (state.mode === 'owners') {
        showOwners(portfolio);
      } else {
        showSubPortfolios(portfolio);
      }
      return;
    }
    if (type === 'owner') {
      const portfolio = tile.getAttribute('data-portfolio') || state.portfolio;
      const ownerKey = tile.getAttribute('data-owner-key') || tile.getAttribute('data-key') || '';
      const ownerName = tile.getAttribute('data-owner-name') || tile.getAttribute('data-label') || '';
      if (portfolio && ownerKey) showProjects(portfolio, '', ownerKey, ownerName);
      return;
    }
    if (type === 'sub_portfolio') {
      const portfolio = tile.getAttribute('data-portfolio') || state.portfolio;
      const sub = tile.getAttribute('data-sub-portfolio') || tile.getAttribute('data-label') || '';
      if (portfolio && sub) showProjects(portfolio, sub);
      return;
    }
    if (type === 'project') {
      const projectName = tile.getAttribute('data-project-name') || tile.getAttribute('data-label') || '';
      const sourceKey = tile.getAttribute('data-source-key') || '';
      if (typeof window.RiskRegisterSharePoint?.openProject === 'function' && projectName) {
        window.RiskRegisterSharePoint.openProject(projectName, sourceKey);
      }
    }
  });

  breadcrumbEl?.addEventListener('click', (event) => {
    const crumb = event.target.closest('[data-portfolio-level]');
    if (!crumb) return;
    const level = crumb.getAttribute('data-portfolio-level');
    if (level === 'portfolios') {
      showPortfolios();
      return;
    }
    const portfolio = crumb.getAttribute('data-portfolio') || state.portfolio;
    if (level === 'sub_portfolios' && portfolio) {
      showSubPortfolios(portfolio);
      return;
    }
    if (level === 'owners' && portfolio) {
      showOwners(portfolio);
      return;
    }
    if (level === 'projects' && portfolio) {
      const sub = crumb.getAttribute('data-sub-portfolio') || '';
      const ownerKey = crumb.getAttribute('data-owner-key') || '';
      const ownerName = crumb.getAttribute('data-owner-name') || '';
      showProjects(portfolio, sub, ownerKey, ownerName);
    }
  });

  document.getElementById('sp-portfolio-mode')?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-portfolio-mode]');
    if (!btn) return;
    setMode(btn.getAttribute('data-portfolio-mode') || 'storage');
  });

  document.getElementById('sp-portfolio-chart-type')?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-portfolio-chart]');
    if (!btn) return;
    setChartType(btn.getAttribute('data-portfolio-chart') || 'heatmap');
  });

  document.getElementById('sp-portfolio-chart-sort')?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-portfolio-chart-sort]');
    if (!btn) return;
    setChartSort(btn.getAttribute('data-portfolio-chart-sort') || 'desc');
  });

  backBtn?.addEventListener('click', goBack);
  refreshBtn?.addEventListener('click', () => load(true));

  filesTable?.addEventListener('click', (event) => {
    const sortBtn = event.target.closest('[data-portfolio-file-sort-button]');
    if (sortBtn) {
      const key = sortBtn.getAttribute('data-portfolio-file-sort-button') || 'size';
      if (state.fileSort.key === key) {
        state.fileSort.direction = state.fileSort.direction === 'asc' ? 'desc' : 'asc';
      } else {
        state.fileSort.key = key;
        state.fileSort.direction = key === 'size' ? 'desc' : 'asc';
      }
      renderFiles();
      return;
    }
    const projectBtn = event.target.closest('.sp-portfolio-project-link');
    if (projectBtn) {
      const projectName = projectBtn.getAttribute('data-project-name') || '';
      const sourceKey = projectBtn.getAttribute('data-source-key') || '';
      if (typeof window.RiskRegisterSharePoint?.openProject === 'function' && projectName) {
        window.RiskRegisterSharePoint.openProject(projectName, sourceKey);
      }
    }
  });

  projectSearch?.addEventListener('input', () => {
    state.projectSearch = projectSearch.value || '';
    applyProjectFiltersAndRedraw();
  });
  projectUniqueToggle?.addEventListener('change', () => {
    state.projectUniqueOnly = !!projectUniqueToggle.checked;
    applyProjectFiltersAndRedraw();
  });

  projectTable?.addEventListener('change', (event) => {
    const select = event.target.closest('[data-portfolio-filter]');
    if (!select || !projectTable.contains(select)) return;
    const key = select.getAttribute('data-portfolio-filter');
    if (!key || !(key in state.projectFilters)) return;
    state.projectFilters[key] = select.value || '';
    if (key === 'portfolio') {
      state.projectFilters.sub = '';
    }
    applyProjectFiltersAndRedraw();
  });

  const clearAllProjectFilters = () => {
    state.projectFilters = { source: '', owner: '', portfolio: '', sub: '' };
    state.projectSearch = '';
    state.projectUniqueOnly = false;
    if (projectSearch) projectSearch.value = '';
    if (projectUniqueToggle) projectUniqueToggle.checked = false;
    applyProjectFiltersAndRedraw();
  };

  document.getElementById('sp-portfolio-filters-clear')?.addEventListener('click', () => {
    clearAllProjectFilters();
  });

  chartFiltersEl?.addEventListener('click', (event) => {
    const clearBtn = event.target.closest('#sp-portfolio-chart-filters-clear');
    if (!clearBtn) return;
    clearAllProjectFilters();
  });

  projectTable?.addEventListener('click', (event) => {
    const sortBtn = event.target.closest('[data-portfolio-project-sort-button]');
    if (sortBtn) {
      const key = sortBtn.getAttribute('data-portfolio-project-sort-button') || 'size';
      if (state.projectSort.key === key) {
        state.projectSort.direction = state.projectSort.direction === 'asc' ? 'desc' : 'asc';
      } else {
        state.projectSort.key = key;
        state.projectSort.direction = key === 'size' || key === 'files' ? 'desc' : 'asc';
      }
      renderProjectMenu();
      return;
    }
    const projectBtn = event.target.closest('.sp-project-submenu-project-link');
    if (projectBtn) {
      const projectName = projectBtn.getAttribute('data-project-name') || '';
      const sourceKey = projectBtn.getAttribute('data-source-key') || '';
      if (typeof window.RiskRegisterSharePoint?.openProject === 'function' && projectName) {
        window.RiskRegisterSharePoint.openProject(projectName, sourceKey);
      }
      return;
    }
    const editBtn = event.target.closest('.sp-portfolio-map-edit');
    if (editBtn) {
      event.preventDefault();
      openMappingEditor({
        project_name: editBtn.getAttribute('data-project-name') || '',
        source_key: editBtn.getAttribute('data-source-key') || '',
        portfolio: editBtn.getAttribute('data-portfolio') || '',
        sub_portfolio: editBtn.getAttribute('data-sub-portfolio') || '',
        confidence: editBtn.getAttribute('data-confidence') || 'Medium',
        note: editBtn.getAttribute('data-note') || '',
      });
      return;
    }
    const ownerBtn = event.target.closest('.sp-project-submenu-owner-link');
    if (ownerBtn) {
      switchToOwnerTab(
        ownerBtn.getAttribute('data-owner-key') || '',
        ownerBtn.getAttribute('data-owner-name') || ''
      );
    }
  });

  mapPortfolio?.addEventListener('change', () => {
    fillSubSelect(mapPortfolio.value, '');
    syncNewFieldsUi();
    if (mapPortfolio.value === NEW_PORTFOLIO_VALUE) {
      mapPortfolioCustom?.focus();
    }
  });
  mapSub?.addEventListener('change', () => {
    syncNewFieldsUi();
    if (mapSub.value === NEW_SUB_VALUE) {
      mapSubCustom?.focus();
    }
  });
  document.getElementById('sp-portfolio-map-cancel')?.addEventListener('click', () => {
    if (mapDialog?.open) mapDialog.close();
  });
  document.getElementById('sp-portfolio-map-dialog-close')?.addEventListener('click', () => {
    if (mapDialog?.open) mapDialog.close();
  });
  mapForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    saveMapping();
  });

  const importDialog = document.getElementById('sp-portfolio-import-dialog');
  const importForm = document.getElementById('sp-portfolio-import-form');
  const importFile = document.getElementById('sp-portfolio-import-file');
  const importError = document.getElementById('sp-portfolio-import-error');
  const importResult = document.getElementById('sp-portfolio-import-result');
  const importSave = document.getElementById('sp-portfolio-import-save');

  const setImportError = (message) => {
    if (!importError) return;
    if (!message) {
      importError.hidden = true;
      importError.textContent = '';
      return;
    }
    importError.hidden = false;
    importError.textContent = message;
  };

  const setImportResult = (message) => {
    if (!importResult) return;
    if (!message) {
      importResult.hidden = true;
      importResult.textContent = '';
      return;
    }
    importResult.hidden = false;
    importResult.textContent = message;
  };

  const exportMappingCsv = () => {
    if (!state.canEditPortfolio) return;
    window.location.href = 'sharepoint.php?action=export_portfolio_mapping';
  };

  const exportMappingTemplate = () => {
    if (!state.canEditPortfolio) return;
    window.location.href = 'sharepoint.php?action=export_portfolio_mapping_template';
  };

  const openImportDialog = () => {
    if (!state.canEditPortfolio || !importDialog || typeof importDialog.showModal !== 'function') return;
    setImportError('');
    setImportResult('');
    if (importFile) importFile.value = '';
    const appendRadio = importForm?.querySelector('input[name="sp-portfolio-import-mode"][value="append"]');
    if (appendRadio) appendRadio.checked = true;
    const keepUnique = document.getElementById('sp-portfolio-import-keep-unique');
    if (keepUnique) keepUnique.checked = true;
    importDialog.showModal();
  };

  const importMappingCsv = async () => {
    if (!state.canEditPortfolio) return;
    const file = importFile?.files?.[0];
    if (!file) {
      setImportError('Choose a CSV file to import.');
      return;
    }
    const mode = importForm?.querySelector('input[name="sp-portfolio-import-mode"]:checked')?.value === 'replace'
      ? 'replace'
      : 'append';
    const keepUnique = document.getElementById('sp-portfolio-import-keep-unique')?.checked !== false;
    if (mode === 'replace') {
      const ok = window.confirm(
        'Replace ALL portfolio mapping rows with this CSV?\n\nA backup file will be created first. This cannot be undone from the UI.'
      );
      if (!ok) return;
    }
    setImportError('');
    setImportResult('');
    if (importSave) {
      importSave.disabled = true;
      importSave.textContent = 'Importing…';
    }
    try {
      const body = new FormData();
      body.set('csrf_token', csrfToken());
      body.set('action', 'import_portfolio_mapping');
      body.set('ajax', '1');
      body.set('mode', mode);
      body.set('keep_unique', keepUnique ? '1' : '0');
      body.set('import_file', file, file.name || 'portfolio_mapping.csv');
      const response = await fetch('sharepoint.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body,
      });
      const data = await response.json();
      if (!response.ok || data.ok === false) {
        throw new Error(data.error || `Import failed (${response.status})`);
      }
      const result = data.result || {};
      if (Array.isArray(data.options)) {
        state.mappingOptions = data.options;
      }
      const summary = mode === 'replace'
        ? `Replaced mapping: ${result.after ?? 0} rows (was ${result.before ?? 0}).`
        : `Import complete: +${result.added ?? 0} added, ${result.updated ?? 0} updated, ${result.unchanged ?? 0} unchanged (${result.after ?? 0} total).`;
      const skippedNote = Number(result.skipped) > 0 ? ` Skipped ${result.skipped} invalid row(s).` : '';
      const dupNote = Number(result.duplicates) > 0
        ? ` Filtered ${result.duplicates} duplicate project row(s).`
        : '';
      setImportResult(summary + skippedNote + dupNote);
      state.loaded = false;
      state.data = null;
      await load(true);
      window.setTimeout(() => {
        if (importDialog?.open) importDialog.close();
      }, 900);
    } catch (err) {
      setImportError(err?.message || 'Could not import portfolio mapping CSV.');
    } finally {
      if (importSave) {
        importSave.disabled = false;
        importSave.textContent = 'Import CSV';
      }
    }
  };

  document.getElementById('sp-portfolio-export')?.addEventListener('click', exportMappingCsv);
  document.getElementById('sp-portfolio-template')?.addEventListener('click', exportMappingTemplate);
  document.getElementById('sp-portfolio-import-template-link')?.addEventListener('click', exportMappingTemplate);
  document.getElementById('sp-portfolio-import')?.addEventListener('click', openImportDialog);
  document.getElementById('sp-portfolio-import-cancel')?.addEventListener('click', () => {
    if (importDialog?.open) importDialog.close();
  });
  document.getElementById('sp-portfolio-import-dialog-close')?.addEventListener('click', () => {
    if (importDialog?.open) importDialog.close();
  });
  importForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    importMappingCsv();
  });

  // Prefetch dropdown options for admins once the portfolio tab is used.
  if (state.canEditPortfolio) {
    loadMappingOptions().catch(() => { /* ignore prefetch errors */ });
  }

  scopesRoot?.addEventListener('change', (event) => {
    if (!event.target.classList.contains('sp-portfolio-scope-check')) return;
    const checks = root.querySelectorAll('.sp-portfolio-scope-check:checked');
    if (!checks.length) {
      event.target.checked = true;
      return;
    }
    const keys = readSelectedSources();
    saveSelectedSources(keys);
    updateScopesUi();
    showPortfolios();
  });

  document.getElementById('sp-portfolio-scopes-all')?.addEventListener('click', () => {
    root.querySelectorAll('.sp-portfolio-scope-check').forEach((el) => {
      el.checked = true;
    });
    const keys = readSelectedSources();
    saveSelectedSources(keys);
    updateScopesUi();
    showPortfolios();
  });

  let resizeTimer = 0;
  const scheduleTreemapResize = () => {
    if (!state.loaded || !state.data) return;
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(() => renderChart(currentChartNodes()), 120);
  };
  window.addEventListener('resize', scheduleTreemapResize);
  if (typeof ResizeObserver === 'function' && treemapWrap) {
    const resizeObserver = new ResizeObserver(scheduleTreemapResize);
    resizeObserver.observe(treemapWrap);
  }

  syncScopesFromStorage();
  state.mode = readPersistedMode();
  state.chartType = readPersistedChartType();
  state.chartSort = readPersistedChartSort();
  const savedNav = readNavigation();
  if (savedNav?.mode) {
    state.mode = normalizeMode(savedNav.mode);
  }
  if (savedNav?.level === 'projects' && savedNav.portfolio) {
    state.level = 'projects';
    state.portfolio = savedNav.portfolio;
    state.subPortfolio = state.mode === 'storage' ? (savedNav.subPortfolio || '') : '';
    state.ownerKey = state.mode === 'owners' ? (savedNav.ownerKey || '') : '';
    state.ownerName = state.mode === 'owners' ? (savedNav.ownerName || '') : '';
  } else if (savedNav?.level === 'owners' && savedNav.portfolio && state.mode === 'owners') {
    state.level = 'owners';
    state.portfolio = savedNav.portfolio;
    state.subPortfolio = '';
    state.ownerKey = '';
    state.ownerName = '';
  } else if (savedNav?.level === 'sub_portfolios' && savedNav.portfolio && state.mode === 'storage') {
    state.level = 'sub_portfolios';
    state.portfolio = savedNav.portfolio;
    state.subPortfolio = '';
    state.ownerKey = '';
    state.ownerName = '';
  }
  setModeUi();
  setChartTypeUi();
  renderBreadcrumb();

  window.RiskRegisterPortfolioStorage = {
    load: (force = false) => {
      if (force) {
        state.loaded = false;
      }
      if (!state.loaded && !force && state.level === 'portfolios' && !state.portfolio) {
        const saved = readNavigation();
        if (saved?.mode) {
          state.mode = normalizeMode(saved.mode);
        }
        if (saved?.level === 'projects' && saved.portfolio) {
          state.level = 'projects';
          state.portfolio = saved.portfolio;
          state.subPortfolio = state.mode === 'storage' ? (saved.subPortfolio || '') : '';
          state.ownerKey = state.mode === 'owners' ? (saved.ownerKey || '') : '';
          state.ownerName = state.mode === 'owners' ? (saved.ownerName || '') : '';
        } else if (saved?.level === 'owners' && saved.portfolio && state.mode === 'owners') {
          state.level = 'owners';
          state.portfolio = saved.portfolio;
          state.subPortfolio = '';
          state.ownerKey = '';
          state.ownerName = '';
        } else if (saved?.level === 'sub_portfolios' && saved.portfolio && state.mode === 'storage') {
          state.level = 'sub_portfolios';
          state.portfolio = saved.portfolio;
          state.subPortfolio = '';
          state.ownerKey = '';
          state.ownerName = '';
        }
        setModeUi();
        setChartTypeUi();
      }
      return load(force);
    },
    isLoaded: () => state.loaded,
    invalidate: () => {
      state.loaded = false;
      state.data = null;
      state.level = 'portfolios';
      state.portfolio = '';
      state.subPortfolio = '';
      state.ownerKey = '';
      state.ownerName = '';
      saveNavigation();
    },
    redraw: () => {
      if (state.loaded && state.data) renderChart(currentChartNodes());
    },
    syncScopesFromStorage,
  };
})();
