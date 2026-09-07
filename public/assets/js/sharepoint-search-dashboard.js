(() => {
  const dialog = document.getElementById('sharepoint-search-dash-dialog');
  const bodyEl = document.getElementById('sharepoint-search-dash-body');
  const titleEl = document.getElementById('sharepoint-search-dash-title');
  const subEl = document.getElementById('sharepoint-search-dash-sub');
  const closeBtn = document.getElementById('sharepoint-search-dash-close');
  const statsEl = document.getElementById('sharepoint-search-stats');
  if (!dialog || !bodyEl) return;

  const Fuzzy = window.FuzzySearch;
  const api = () => window.RiskRegisterSharePoint || {};
  const escapeHtml = (value) =>
    typeof api().escapeHtml === 'function'
      ? api().escapeHtml(value)
      : String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');

  const MAX_RENDER_HITS = 500;
  const PER_PROJECT_PREVIEW = 24;
  const DETAIL_CONCURRENCY = 3;
  const EXPECTED_CHECKS = [
    { key: 'pdf', label: 'PDF', test: (row, hits) => !!(row.project?._hasPdf || hits.some((h) => fileExtension(h.name) === 'pdf')) },
    {
      key: 'visio',
      label: 'Visio',
      test: (row, hits) => !!(row.project?._hasVisio || hits.some((h) => ['vsd', 'vsdx'].includes(fileExtension(h.name)))),
    },
    {
      key: 'excel',
      label: 'Excel',
      test: (row, hits) =>
        !!(row.project?._hasExcel || hits.some((h) => ['xls', 'xlsx', 'xlsm', 'csv'].includes(fileExtension(h.name)))),
    },
    {
      key: 'sow',
      label: 'SOW',
      test: (_row, hits) =>
        hits.some((h) => /\bsow\b/i.test(`${h.name} ${h.path || ''}`)) ||
        /\bsow\b/i.test(String(_row.project?.project_name || '')),
    },
  ];
  const TYPE_BUCKETS = [
    { key: 'pdf', label: 'PDF', match: (ext, kind) => kind !== 'folder' && ext === 'pdf' },
    { key: 'excel', label: 'Excel', match: (ext, kind) => kind !== 'folder' && ['xls', 'xlsx', 'xlsm', 'csv'].includes(ext) },
    { key: 'email', label: 'Email', match: (ext, kind) => kind !== 'folder' && ['msg', 'eml'].includes(ext) },
    { key: 'visio', label: 'Visio', match: (ext, kind) => kind !== 'folder' && ['vsd', 'vsdx'].includes(ext) },
    { key: 'word', label: 'Word', match: (ext, kind) => kind !== 'folder' && ['doc', 'docx', 'rtf'].includes(ext) },
    { key: 'folder', label: 'Folders', match: (_ext, kind) => kind === 'folder' },
    { key: 'other', label: 'Other', match: () => true },
  ];
  const SORT_OPTIONS = [
    { key: 'score', label: 'Match %' },
    { key: 'fresh', label: 'Freshness' },
    { key: 'name', label: 'Name' },
    { key: 'catalog', label: 'Catalog' },
    { key: 'person', label: 'Person' },
    { key: 'hits', label: 'Nested hits' },
  ];
  const GROUP_OPTIONS = [
    { key: '', label: 'No grouping' },
    { key: 'catalog', label: 'Catalog' },
    { key: 'fresh', label: 'Freshness' },
    { key: 'person', label: 'Person' },
    { key: 'type', label: 'Top file type' },
  ];

  const publicShare = document.getElementById('sharepoint-search')?.getAttribute('data-public') === '1';
  const CONTROLS_KEY = publicShare
    ? 'riskregister_sp_public_search_dash_controls'
    : 'riskregister_sp_search_dash_controls';
  const HIT_SORT_KEYS = new Set(['modified', 'name', 'path', 'type', 'score']);

  const readPersistedControls = () => {
    try {
      const raw = JSON.parse(localStorage.getItem(CONTROLS_KEY) || 'null');
      return raw && typeof raw === 'object' ? raw : null;
    } catch {
      return null;
    }
  };

  const persistDashboardUi = () => {
    try {
      localStorage.setItem(
        CONTROLS_KEY,
        JSON.stringify({
          sortBy: ui.sortBy,
          groupBy: ui.groupBy,
          hitsView: ui.hitsView,
          hitSortKey: ui.hitSortKey,
          hitSortDir: ui.hitSortDir,
        })
      );
    } catch {
      /* ignore */
    }
  };

  const ui = {
    typeFilter: '',
    catalogFilter: '',
    personFilter: '',
    sortBy: 'score',
    groupBy: '',
    hitsView: 'table',
    hitSortKey: 'modified',
    hitSortDir: 'desc',
    selected: new Set(),
    expanded: new Set(),
    urlMap: new Map(),
    loadingUrls: false,
    abort: null,
    hitCache: new Map(),
    detailMeta: new Map(),
  };

  // Restore layout controls before first paint (table is default).
  (() => {
    const saved = readPersistedControls();
    if (!saved) return;
    if (SORT_OPTIONS.some((o) => o.key === saved.sortBy)) ui.sortBy = saved.sortBy;
    if (GROUP_OPTIONS.some((o) => o.key === saved.groupBy)) ui.groupBy = saved.groupBy;
    ui.hitsView = saved.hitsView === 'chips' ? 'chips' : 'table';
    if (HIT_SORT_KEYS.has(saved.hitSortKey)) ui.hitSortKey = saved.hitSortKey;
    ui.hitSortDir = saved.hitSortDir === 'asc' ? 'asc' : 'desc';
  })();


  let currentSnapshot = null;
  let allowClose = false;

  if (typeof api().bindWorkspaceDialog === 'function') {
    api().bindWorkspaceDialog(dialog);
  }

  const close = () => {
    allowClose = true;
    if (typeof dialog.close === 'function' && dialog.open) dialog.close();
    else dialog.removeAttribute('open');
  };

  closeBtn?.addEventListener('click', (event) => {
    event.preventDefault();
    close();
  });

  dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
  });

  dialog.addEventListener('close', () => {
    if (!allowClose && currentSnapshot) {
      window.requestAnimationFrame(() => {
        if (dialog.open || !currentSnapshot) return;
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
        dialog.__spPrepareWorkspace?.();
      });
      return;
    }
    allowClose = false;
    currentSnapshot = null;
    if (ui.abort) {
      try {
        ui.abort.abort();
      } catch {
        /* ignore */
      }
      ui.abort = null;
    }
  });

  const fileExtension = (name) =>
    typeof api().fileExtension === 'function'
      ? api().fileExtension(name)
      : (() => {
          const base = String(name || '').split(/[\\/]/).pop() || '';
          const dot = base.lastIndexOf('.');
          if (dot <= 0 || dot === base.length - 1) return '';
          return base.slice(dot + 1).toLowerCase();
        })();

  const resolveMeta = (item) => {
    if (typeof api().resolveMeta === 'function') return api().resolveMeta(item);
    const type = String(item?.item_type || item?.kind || 'file').toLowerCase();
    if (type === 'folder') return { emoji: '📁', label: 'Folder', tone: 'folder' };
    const ext = fileExtension(item?.name);
    return { emoji: '📄', label: ext ? ext.toUpperCase() : 'File', tone: 'file' };
  };

  const typeBucketFor = (hit) => {
    const kind = String(hit.kind || hit.item_type || 'file').toLowerCase() === 'folder' ? 'folder' : 'file';
    const ext = fileExtension(hit.name);
    for (const bucket of TYPE_BUCKETS) {
      if (bucket.key === 'other') continue;
      if (bucket.match(ext, kind)) return bucket;
    }
    return TYPE_BUCKETS[TYPE_BUCKETS.length - 1];
  };

  const projectKey = (project) => `${String(project?.source_key || '')}::${String(project?.project_name || '')}`;

  const hitLookupKey = (sourceKey, projectName, path, name) =>
    `${String(sourceKey || '')}::${String(projectName || '')}::${String(path || name || '')
      .trim()
      .toLowerCase()}`;

  const parseQueryWords = (snapshot) => {
    const raw = String(snapshot?.query || '').trim();
    if (!raw || !Fuzzy) return [];
    if (Fuzzy.parseCatalogQuery) {
      const parsed = Fuzzy.parseCatalogQuery(raw);
      return [...(parsed.words || []), ...(parsed.phrases || [])];
    }
    return Fuzzy.getSearchWords?.(raw) || [];
  };

  const collectAllHits = (row, snapshot) => {
    const key = projectKey(row.project);
    if (ui.hitCache.has(key)) return ui.hitCache.get(key);
    const words = parseQueryWords(snapshot);
    const useDeep = snapshot.deep || snapshot.matchScope === 'files';
    let hits = [];
    if (useDeep && words.length && typeof api().collectDeepHits === 'function') {
      const set = api().collectDeepHits(row.project, words, snapshot.wordMode || 'and', !!snapshot.fuzzy, Infinity);
      hits = set?.hits || [];
    } else if (row.deepHits?.hits?.length) {
      hits = [...row.deepHits.hits];
    }
    ui.hitCache.set(key, hits);
    return hits;
  };

  const buildUrlIndex = (projectName, sourceKey, items) => {
    const map = new Map();
    (items || []).forEach((item) => {
      const url = String(item?.web_url || '').trim();
      if (!url) return;
      const name = String(item?.name || '').trim();
      const path = String(item?.relative_path || '').trim() || name;
      if (path) map.set(hitLookupKey(sourceKey, projectName, path, name), url);
      if (name && name !== path) map.set(hitLookupKey(sourceKey, projectName, name, name), url);
    });
    return map;
  };

  const resolveHitUrl = (row, hit) => {
    const sourceKey = String(row.project?.source_key || '');
    const projectName = String(row.project?.project_name || '');
    const path = String(hit.path || hit.name || '');
    const name = String(hit.name || '');
    return (
      ui.urlMap.get(hitLookupKey(sourceKey, projectName, path, name)) ||
      ui.urlMap.get(hitLookupKey(sourceKey, projectName, name, name)) ||
      ''
    );
  };

  const folderUrlFor = (row) => {
    const name = String(row.project?.project_name || '');
    const sourceKey = String(row.project?.source_key || '');
    return (
      String(row.project?.folder_url || '').trim() ||
      ui.urlMap.get(hitLookupKey(sourceKey, name, '__folder__', name)) ||
      ''
    );
  };

  const mapPool = async (items, concurrency, worker, signal) => {
    const list = [...items];
    let index = 0;
    const runners = Array.from({ length: Math.max(1, concurrency) }, async () => {
      while (index < list.length) {
        if (signal?.aborted) return;
        const current = list[index];
        index += 1;
        await worker(current);
      }
    });
    await Promise.all(runners);
  };

  const loadDetailUrls = async (rows) => {
    if (typeof api().fetchProjectDetail !== 'function') return;
    if (ui.abort) {
      try {
        ui.abort.abort();
      } catch {
        /* ignore */
      }
    }
    ui.abort = new AbortController();
    const signal = ui.abort.signal;
    ui.loadingUrls = true;
    syncLoadingHint();

    const unique = [];
    const seen = new Set();
    rows.forEach((row) => {
      const key = projectKey(row.project);
      if (seen.has(key)) return;
      seen.add(key);
      unique.push(row);
    });

    await mapPool(
      unique,
      DETAIL_CONCURRENCY,
      async (row) => {
        if (signal.aborted) return;
        const name = String(row.project?.project_name || '');
        const sourceKey = String(row.project?.source_key || '');
        try {
          const payload = await api().fetchProjectDetail(name, sourceKey);
          if (signal.aborted) return;
          const items = payload?.project?.items || [];
          const folderUrl = String(payload?.project?.folder_url || row.project?.folder_url || '').trim();
          if (folderUrl) ui.urlMap.set(hitLookupKey(sourceKey, name, '__folder__', name), folderUrl);
          buildUrlIndex(name, sourceKey, items).forEach((url, key) => ui.urlMap.set(key, url));
          const byPath = new Map();
          items.forEach((item) => {
            const path = String(item.relative_path || item.name || '')
              .trim()
              .toLowerCase();
            if (path) byPath.set(path, item);
            const n = String(item.name || '')
              .trim()
              .toLowerCase();
            if (n) byPath.set(n, item);
          });
          ui.detailMeta.set(`${sourceKey}::${name}`, byPath);
        } catch {
          /* keep fallbacks */
        }
      },
      signal
    );

    if (!signal.aborted) {
      ui.loadingUrls = false;
      syncLoadingHint();
      renderBody(currentSnapshot, false);
    }
  };

  const syncLoadingHint = () => {
    const hint = bodyEl.querySelector('.sp-sd-loading');
    if (!hint) return;
    hint.hidden = !ui.loadingUrls;
  };

  const hitDetailItem = (row, hit) => {
    const sourceKey = String(row.project?.source_key || '');
    const projectName = String(row.project?.project_name || '');
    const map = ui.detailMeta.get(`${sourceKey}::${projectName}`);
    if (!map) return null;
    const path = String(hit.path || hit.name || '')
      .trim()
      .toLowerCase();
    return map.get(path) || map.get(String(hit.name || '').trim().toLowerCase()) || null;
  };

  const hitModifiedMs = (row, hit) => {
    const item = hitDetailItem(row, hit);
    if (item?.last_modified) {
      const ms = Date.parse(item.last_modified);
      if (Number.isFinite(ms)) return ms;
    }
    return null;
  };

  const formatHitDateTime = (ms) => {
    if (ms == null || !Number.isFinite(ms)) return '—';
    if (typeof api().formatModified === 'function') {
      return api().formatModified(new Date(ms).toISOString());
    }
    try {
      return new Date(ms).toLocaleString();
    } catch {
      return '—';
    }
  };

  const sortHitsList = (row, hits) => {
    const list = [...(hits || [])];
    const dir = ui.hitSortDir === 'asc' ? 1 : -1;
    list.sort((a, b) => {
      let cmp = 0;
      if (ui.hitSortKey === 'name') {
        cmp = String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' });
      } else if (ui.hitSortKey === 'path') {
        cmp = String(a.path || a.name || '').localeCompare(String(b.path || b.name || ''), undefined, {
          sensitivity: 'base',
        });
      } else if (ui.hitSortKey === 'type') {
        cmp = typeBucketFor(a).label.localeCompare(typeBucketFor(b).label);
      } else if (ui.hitSortKey === 'score') {
        cmp = (a.match?.score || row.match?.score || 0) - (b.match?.score || row.match?.score || 0);
      } else {
        // modified (default) — undated last when newest-first
        const am = hitModifiedMs(row, a);
        const bm = hitModifiedMs(row, b);
        if (am == null && bm == null) cmp = 0;
        else if (am == null) cmp = 1;
        else if (bm == null) cmp = -1;
        else cmp = am - bm;
      }
      if (cmp) return cmp * dir;
      return String(a.name || '').localeCompare(String(b.name || ''));
    });
    return list;
  };

  const confidenceText = (match) => {
    if (!match?.matched) return 'No deep match detail';
    const FuzzyApi = Fuzzy || {};
    const label = FuzzyApi.matchKindLabel?.(match.kind) || match.kind || 'match';
    const reason = FuzzyApi.formatMatchReason?.(match) || '';
    const token = match.token ? ` · token “${match.token}”` : '';
    const snippet = match.snippet ? ` · “${match.snippet}”` : '';
    return `${match.score}% ${label}${reason ? ` · ${reason}` : ''}${token}${snippet}`;
  };

  const pathBreadcrumbsHtml = (path, name) => {
    const raw = String(path || name || '').trim();
    if (!raw) return '';
    const parts = raw.split(/[\\/]/).filter(Boolean);
    if (parts.length <= 1) return `<span class="sp-sd-crumb">${escapeHtml(parts[0] || name || '')}</span>`;
    return `<span class="sp-sd-crumbs" title="${escapeHtml(raw)}">${parts
      .map((part, idx) => {
        const isLast = idx === parts.length - 1;
        return `<span class="sp-sd-crumb${isLast ? ' is-file' : ''}">${escapeHtml(part)}</span>${
          isLast ? '' : '<span class="sp-sd-crumb-sep">/</span>'
        }`;
      })
      .join('')}</span>`;
  };

  const catalogBreakdown = (rows) => {
    const counts = new Map();
    rows.forEach((row) => {
      const key = String(row.project?.source_key || '');
      const title = String(row.project?.source_title || key || 'Catalog');
      if (!counts.has(key)) counts.set(key, { key, title, count: 0 });
      counts.get(key).count += 1;
    });
    return [...counts.values()].sort((a, b) => b.count - a.count || a.title.localeCompare(b.title));
  };

  const typeBreakdown = (rows, snapshot) => {
    const counts = Object.fromEntries(TYPE_BUCKETS.map((b) => [b.key, 0]));
    rows.forEach((row) => {
      collectAllHits(row, snapshot).forEach((hit) => {
        counts[typeBucketFor(hit).key] += 1;
      });
    });
    return TYPE_BUCKETS.map((b) => ({ ...b, count: counts[b.key] || 0 })).filter((b) => b.count > 0);
  };

  const freshnessBreakdown = (rows) => {
    const counts = { fresh: 0, normal: 0, stale: 0, unknown: 0 };
    rows.forEach((row) => {
      const bucket = row.project?._freshness || 'unknown';
      if (counts[bucket] == null) counts.unknown += 1;
      else counts[bucket] += 1;
    });
    return [
      { key: 'fresh', label: 'Fresh', count: counts.fresh },
      { key: 'normal', label: 'Aging', count: counts.normal },
      { key: 'stale', label: 'Stale', count: counts.stale },
      { key: 'unknown', label: 'No date', count: counts.unknown },
    ].filter((item) => item.count > 0);
  };

  const peopleBreakdown = (rows) => {
    const counts = new Map();
    rows.forEach((row) => {
      [row.project?.modified_by, row.project?.person]
        .map((name) => String(name || '').trim())
        .filter(Boolean)
        .forEach((name) => counts.set(name, (counts.get(name) || 0) + 1));
    });
    return [...counts.entries()]
      .map(([name, count]) => ({ name, count, key: name }))
      .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name))
      .slice(0, 12);
  };

  const freshnessRank = (row) => {
    const f = row.project?._freshness;
    if (f === 'fresh') return 0;
    if (f === 'normal') return 1;
    if (f === 'stale') return 2;
    return 3;
  };

  const topTypeKey = (row, snapshot) => {
    const hits = collectAllHits(row, snapshot);
    if (!hits.length) return 'other';
    const counts = {};
    hits.forEach((hit) => {
      const key = typeBucketFor(hit).key;
      counts[key] = (counts[key] || 0) + 1;
    });
    return Object.entries(counts).sort((a, b) => b[1] - a[1])[0]?.[0] || 'other';
  };

  const personLabel = (row) =>
    String(row.project?.modified_by || row.project?.person || 'Unassigned').trim() || 'Unassigned';

  const filteredRows = (snapshot) => {
    let rows = [...(snapshot.rows || [])];
    if (ui.catalogFilter) {
      rows = rows.filter((row) => String(row.project?.source_key || '') === ui.catalogFilter);
    }
    if (ui.personFilter) {
      const needle = ui.personFilter.toLowerCase();
      rows = rows.filter((row) => {
        const people = [row.project?.modified_by, row.project?.person].map((n) => String(n || '').toLowerCase());
        return people.includes(needle);
      });
    }
    if (ui.typeFilter) {
      rows = rows
        .map((row) => {
          const hits = collectAllHits(row, snapshot).filter((hit) => typeBucketFor(hit).key === ui.typeFilter);
          return { ...row, _dashHits: hits };
        })
        .filter((row) => row._dashHits.length > 0);
    }

    rows.sort((a, b) => {
      if (ui.sortBy === 'name') {
        return String(a.project?.project_name || '').localeCompare(String(b.project?.project_name || ''));
      }
      if (ui.sortBy === 'catalog') {
        return String(a.project?.source_title || '').localeCompare(String(b.project?.source_title || ''));
      }
      if (ui.sortBy === 'person') {
        return personLabel(a).localeCompare(personLabel(b));
      }
      if (ui.sortBy === 'fresh') {
        const fr = freshnessRank(a) - freshnessRank(b);
        if (fr) return fr;
        return (b.project?._modifiedMs || 0) - (a.project?._modifiedMs || 0);
      }
      if (ui.sortBy === 'hits') {
        const ah = collectAllHits(a, snapshot).length;
        const bh = collectAllHits(b, snapshot).length;
        return bh - ah;
      }
      return (b.match?.score || 0) - (a.match?.score || 0);
    });
    return rows;
  };

  const groupedRows = (rows, snapshot) => {
    if (!ui.groupBy) return [{ key: '', label: '', rows }];
    const groups = new Map();
    rows.forEach((row) => {
      let key = '';
      let label = '';
      if (ui.groupBy === 'catalog') {
        key = String(row.project?.source_key || '');
        label = String(row.project?.source_title || key || 'Catalog');
      } else if (ui.groupBy === 'fresh') {
        key = row.project?._freshness || 'unknown';
        label = { fresh: 'Fresh', normal: 'Aging', stale: 'Stale', unknown: 'No date' }[key] || key;
      } else if (ui.groupBy === 'person') {
        key = personLabel(row);
        label = key;
      } else if (ui.groupBy === 'type') {
        key = topTypeKey(row, snapshot);
        label = TYPE_BUCKETS.find((b) => b.key === key)?.label || key;
      }
      if (!groups.has(key)) groups.set(key, { key, label, rows: [] });
      groups.get(key).rows.push(row);
    });
    return [...groups.values()];
  };

  const kpiHtml = (snapshot) => {
    const rows = snapshot.rows || [];
    const cards = [
      { label: 'Projects', value: rows.length, hint: 'matched folders' },
      { label: 'Nested matches', value: snapshot.nestedMatchTotal || 0, hint: 'files & folders' },
      { label: 'Catalogs hit', value: snapshot.catalogCount || 0, hint: 'across selection' },
      { label: 'Exact', value: snapshot.exactCount || 0, hint: 'exact name hits' },
      { label: 'Similar', value: snapshot.similarCount || 0, hint: 'fuzzy / contains' },
      { label: 'Avg match', value: `${snapshot.avg || 0}%`, hint: 'probability' },
      { label: 'Best match', value: `${snapshot.best || 0}%`, hint: 'top score' },
    ];
    return `<div class="sp-sd-kpis">${cards
      .map(
        (card) => `<div class="sp-sd-kpi">
        <span class="sp-sd-kpi-label">${escapeHtml(card.label)}</span>
        <strong class="sp-sd-kpi-value">${escapeHtml(String(card.value))}</strong>
        <span class="sp-sd-kpi-hint">${escapeHtml(card.hint)}</span>
      </div>`
      )
      .join('')}</div>`;
  };

  const toolbarHtml = () => `<div class="sp-sd-toolbar">
    <label class="sp-sd-tool">
      <span>Sort</span>
      <select data-sd-sort>
        ${SORT_OPTIONS.map(
          (opt) =>
            `<option value="${escapeHtml(opt.key)}"${ui.sortBy === opt.key ? ' selected' : ''}>${escapeHtml(opt.label)}</option>`
        ).join('')}
      </select>
    </label>
    <label class="sp-sd-tool">
      <span>Group</span>
      <select data-sd-group>
        ${GROUP_OPTIONS.map(
          (opt) =>
            `<option value="${escapeHtml(opt.key)}"${ui.groupBy === opt.key ? ' selected' : ''}>${escapeHtml(opt.label)}</option>`
        ).join('')}
      </select>
    </label>
    <div class="sp-sd-view-toggle" role="group" aria-label="File list layout">
      <button type="button" class="sp-sd-view-btn${ui.hitsView === 'chips' ? ' is-active' : ''}" data-hits-view="chips" aria-pressed="${
    ui.hitsView === 'chips' ? 'true' : 'false'
  }" title="Chip cards">▦ Chips</button>
      <button type="button" class="sp-sd-view-btn${ui.hitsView === 'table' ? ' is-active' : ''}" data-hits-view="table" aria-pressed="${
    ui.hitsView === 'table' ? 'true' : 'false'
  }" title="Sortable table with date/time">☰ Table</button>
    </div>
    <div class="sp-sd-tool-actions">
      <button type="button" class="button ghost" data-sd-action="copy-links" title="Copy SharePoint URLs for visible (or selected) files and folders">📋 Copy links</button>
      <button type="button" class="button ghost" data-sd-action="export-csv" title="Download CSV of matched projects and nested files">⬇️ CSV</button>
      <button type="button" class="button ghost" data-sd-action="export-pdf" title="Open a print-ready PDF pack of this dashboard">🖨️ PDF</button>
      <button type="button" class="button ghost" data-sd-action="save-snap" title="Pin this dashboard view next to Saved searches">📌 Save view</button>
      <button type="button" class="button ghost" data-sd-action="clear-sel" title="Clear selected links">Clear selection</button>
    </div>
    <span class="sp-sd-sel-count" data-sd-sel-count>${ui.selected.size} selected</span>
  </div>`;

  const chipBar = (items, activeKey, attr, allLabel) => {
    if (!items.length) return '';
    const chips = [
      `<button type="button" class="sp-sd-chip${!activeKey ? ' is-active' : ''}" data-${attr}="" aria-pressed="${
        !activeKey ? 'true' : 'false'
      }">${escapeHtml(allLabel)}</button>`,
      ...items.map((item) => {
        const key = item.key || item.name;
        const label = item.label || item.name || item.title;
        const active = activeKey === key;
        return `<button type="button" class="sp-sd-chip${active ? ' is-active' : ''}" data-${attr}="${escapeHtml(
          key
        )}" aria-pressed="${active ? 'true' : 'false'}">${escapeHtml(label)} <span class="sp-sd-chip-count">${item.count}</span></button>`;
      }),
    ];
    return `<div class="sp-sd-chips" role="group">${chips.join('')}</div>`;
  };

  const timelineHtml = (rows, snapshot) => {
    const buckets = new Map();
    const push = (ms) => {
      if (ms == null || !Number.isFinite(ms)) {
        buckets.set('unknown', (buckets.get('unknown') || 0) + 1);
        return;
      }
      const d = new Date(ms);
      const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
      buckets.set(key, (buckets.get(key) || 0) + 1);
    };
    rows.forEach((row) => {
      const hits = collectAllHits(row, snapshot);
      if (!hits.length) push(row.project?._modifiedMs ?? null);
      else hits.forEach((hit) => push(hitModifiedMs(row, hit)));
    });
    const entries = [...buckets.entries()].sort((a, b) => {
      if (a[0] === 'unknown') return 1;
      if (b[0] === 'unknown') return -1;
      return a[0].localeCompare(b[0]);
    });
    if (!entries.length) return '<p class="sp-sd-empty-note">No modified dates yet</p>';
    const max = Math.max(...entries.map((e) => e[1]), 1);
    const monthLabel = (key) => {
      if (key === 'unknown') return 'Unknown';
      const [y, m] = key.split('-');
      const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
      return `${names[Number(m) - 1] || m} ${y}`;
    };
    return `<div class="sp-sd-timeline" aria-label="Activity timeline">${entries
      .map(([key, count]) => {
        const pct = Math.max(8, Math.round((count / max) * 100));
        return `<div class="sp-sd-timeline-col" title="${escapeHtml(monthLabel(key))}: ${count}">
          <span class="sp-sd-timeline-bar" style="height:${pct}%"></span>
          <span class="sp-sd-timeline-count">${count}</span>
          <span class="sp-sd-timeline-label">${escapeHtml(monthLabel(key))}</span>
        </div>`;
      })
      .join('')}</div>`;
  };

  const overlapHtml = (snapshot) => {
    const byName = new Map();
    (snapshot.rows || []).forEach((row) => {
      const name = String(row.project?.project_name || '').trim();
      if (!name) return;
      const key = name.toLowerCase();
      if (!byName.has(key)) byName.set(key, { name, rows: [] });
      byName.get(key).rows.push(row);
    });
    const overlaps = [...byName.values()].filter((g) => new Set(g.rows.map((r) => r.project?.source_key)).size > 1);
    if (!overlaps.length) {
      return '<p class="sp-sd-empty-note">No projects appear in multiple catalogs</p>';
    }
    return `<ul class="sp-sd-overlap">${overlaps
      .map((g) => {
        const catalogs = g.rows
          .map((r) => {
            const title = String(r.project?.source_title || r.project?.source_key || '');
            const sourceKey = String(r.project?.source_key || '');
            return `<button type="button" class="sp-sd-overlap-cat" data-open-project="${escapeHtml(
              g.name
            )}" data-source-key="${escapeHtml(sourceKey)}">${escapeHtml(title)}</button>`;
          })
          .join('<span class="sp-sd-overlap-plus">+</span>');
        return `<li><strong>${escapeHtml(g.name)}</strong><div class="sp-sd-overlap-cats">${catalogs}</div></li>`;
      })
      .join('')}</ul>`;
  };

  const missingChecklistHtml = (rows, snapshot) => {
    if (!rows.length) return '<p class="sp-sd-empty-note">No projects to check</p>';
    return `<div class="sp-sd-missing-table">${rows
      .map((row) => {
        const hits = collectAllHits(row, snapshot);
        const flags = EXPECTED_CHECKS.map((check) => {
          const ok = check.test(row, hits);
          return `<span class="sp-sd-miss-flag ${ok ? 'is-ok' : 'is-missing'}" title="${escapeHtml(check.label)}">${
            ok ? '✓' : '!'
          } ${escapeHtml(check.label)}</span>`;
        }).join('');
        const missing = EXPECTED_CHECKS.filter((check) => !check.test(row, hits)).map((c) => c.label);
        return `<div class="sp-sd-miss-row${missing.length ? ' has-gaps' : ''}">
          <button type="button" class="sp-sd-miss-name" data-open-project="${escapeHtml(
            row.project?.project_name || ''
          )}" data-source-key="${escapeHtml(row.project?.source_key || '')}">${escapeHtml(
          row.project?.project_name || ''
        )}</button>
          <div class="sp-sd-miss-flags">${flags}</div>
          ${missing.length ? `<span class="sp-sd-miss-note">Missing ${escapeHtml(missing.join(', '))}</span>` : '<span class="sp-sd-miss-note is-ok">Complete package</span>'}
        </div>`;
      })
      .join('')}</div>`;
  };

  const breakdownHtml = (snapshot, viewRows) => {
    const catalogs = catalogBreakdown(snapshot.rows || []);
    const types = typeBreakdown(snapshot.rows || [], snapshot);
    const freshness = freshnessBreakdown(snapshot.rows || []);
    const people = peopleBreakdown(snapshot.rows || []);
    return `<div class="sp-sd-breakdowns">
      <section class="sp-sd-panel">
        <h4>Catalogs</h4>
        ${chipBar(
          catalogs.map((c) => ({ key: c.key, label: c.title, count: c.count })),
          ui.catalogFilter,
          'catalog-filter',
          'All catalogs'
        )}
      </section>
      <section class="sp-sd-panel">
        <h4>File types</h4>
        ${chipBar(types, ui.typeFilter, 'type-filter', 'All types')}
      </section>
      <section class="sp-sd-panel">
        <h4>Freshness</h4>
        <div class="sp-sd-bars">${freshness
          .map((item) => {
            const max = Math.max(...freshness.map((f) => f.count), 1);
            const pct = Math.max(8, Math.round((item.count / max) * 100));
            return `<div class="sp-sd-bar-row"><span class="sp-sd-bar-label">${escapeHtml(
              item.label
            )}</span><span class="sp-sd-bar-track"><span class="sp-sd-bar-fill sp-sd-bar-fill--${escapeHtml(
              item.key
            )}" style="width:${pct}%"></span></span><strong>${item.count}</strong></div>`;
          })
          .join('')}</div>
      </section>
      <section class="sp-sd-panel">
        <h4>People <span class="sp-sd-panel-hint">click to focus</span></h4>
        ${
          people.length
            ? chipBar(
                people.map((p) => ({ key: p.name, label: p.name, count: p.count })),
                ui.personFilter,
                'person-filter',
                'All people'
              )
            : '<p class="sp-sd-empty-note">No people on these matches</p>'
        }
      </section>
      <section class="sp-sd-panel sp-sd-panel--wide">
        <h4>Timeline / last touched</h4>
        ${timelineHtml(viewRows, snapshot)}
      </section>
      <section class="sp-sd-panel">
        <h4>Overlap map</h4>
        ${overlapHtml(snapshot)}
      </section>
      <section class="sp-sd-panel">
        <h4>Missing-file checklist</h4>
        ${missingChecklistHtml(viewRows, snapshot)}
      </section>
    </div>`;
  };

  const matchBadge = (row) => {
    if (typeof api().scoreBadgeHtml === 'function') return api().scoreBadgeHtml(row.match, row.project);
    const score = Number(row.match?.score || 0);
    const tone = score >= 90 ? 'high' : score >= 75 ? 'mid' : 'low';
    return `<span class="sp-match-badge sp-match-badge--${tone}">${score}%</span>`;
  };

  const selectionKey = (kind, row, hit = null) => {
    const base = projectKey(row.project);
    if (kind === 'folder') return `folder::${base}`;
    return `hit::${base}::${String(hit?.path || hit?.name || '').toLowerCase()}`;
  };

  const hitChipHtml = (row, hit) => {
    const url = resolveHitUrl(row, hit);
    const meta = resolveMeta({ name: hit.name, item_type: hit.kind === 'folder' ? 'folder' : 'file' });
    const conf = confidenceText(hit.match || row.match);
    const selKey = selectionKey('hit', row, hit);
    const checked = ui.selected.has(selKey);
    const crumbs = pathBreadcrumbsHtml(hit.path || hit.name, hit.name);
    const inner = `<span aria-hidden="true">${meta.emoji}</span><span class="sp-sd-hit-copy"><span class="sp-sd-hit-name">${escapeHtml(
      hit.name
    )}</span>${crumbs ? `<span class="sp-sd-hit-crumbs">${crumbs}</span>` : ''}</span>`;
    const select = `<label class="sp-sd-hit-select" title="Select for copy"><input type="checkbox" data-sel-key="${escapeHtml(
      selKey
    )}" data-sel-url="${escapeHtml(url)}" ${checked ? 'checked' : ''}></label>`;
    const confHtml = `<span class="sp-sd-hit-conf" title="${escapeHtml(conf)}">${escapeHtml(
      `${hit.match?.score || row.match?.score || 0}%`
    )}</span>`;
    if (url) {
      return `<span class="sp-sd-hit-wrap">${select}<a class="sp-sd-hit" href="${escapeHtml(
        url
      )}" target="_blank" rel="noopener noreferrer" title="${escapeHtml(conf)}" data-hit-path="${escapeHtml(
        hit.path || hit.name
      )}" data-hit-name="${escapeHtml(hit.name)}" data-hit-kind="${escapeHtml(hit.kind || 'file')}">${inner}${confHtml}</a></span>`;
    }
    return `<span class="sp-sd-hit-wrap">${select}<button type="button" class="sp-sd-hit sp-sd-hit--fallback" title="${escapeHtml(
      conf
    )} (open in catalog)" data-open-project="${escapeHtml(row.project?.project_name || '')}" data-source-key="${escapeHtml(
      row.project?.source_key || ''
    )}" data-hit-query="${escapeHtml(hit.name)}" data-hit-path="${escapeHtml(hit.path || hit.name)}" data-hit-name="${escapeHtml(
      hit.name
    )}" data-hit-kind="${escapeHtml(hit.kind || 'file')}">${inner}${confHtml}</button></span>`;
  };

  const hitSortHeader = (key, label) => {
    const active = ui.hitSortKey === key;
    const arrow = !active ? '' : ui.hitSortDir === 'asc' ? ' ↑' : ' ↓';
    return `<button type="button" class="sp-sd-th-sort${active ? ' is-active' : ''}" data-hit-sort="${escapeHtml(
      key
    )}" aria-pressed="${active ? 'true' : 'false'}">${escapeHtml(label)}${arrow}</button>`;
  };

  const hitTableHtml = (row, hits) => {
    if (!hits.length) {
      return '<p class="sp-sd-empty-note">No nested file hits (folder name match)</p>';
    }
    const rowsHtml = hits
      .map((hit) => {
        const url = resolveHitUrl(row, hit);
        const meta = resolveMeta({ name: hit.name, item_type: hit.kind === 'folder' ? 'folder' : 'file' });
        const detail = hitDetailItem(row, hit);
        const ms = hitModifiedMs(row, hit);
        const when = formatHitDateTime(ms);
        const age =
          ms != null && typeof api().formatAgeLabel === 'function' ? api().formatAgeLabel(ms) : '';
        const path = String(hit.path || hit.name || '');
        const folderPath = path.includes('/') || path.includes('\\')
          ? path.replace(/[\\/][^\\/]+$/, '') || '—'
          : '—';
        const score = hit.match?.score || row.match?.score || 0;
        const conf = confidenceText(hit.match || row.match);
        const selKey = selectionKey('hit', row, hit);
        const checked = ui.selected.has(selKey);
        const nameCell = url
          ? `<a class="sp-sd-table-name" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" title="${escapeHtml(
              conf
            )}" data-hit-path="${escapeHtml(hit.path || hit.name)}" data-hit-name="${escapeHtml(
              hit.name
            )}" data-hit-kind="${escapeHtml(hit.kind || 'file')}"><span aria-hidden="true">${meta.emoji}</span> ${escapeHtml(
              hit.name
            )}</a>`
          : `<button type="button" class="sp-sd-table-name sp-sd-hit--fallback" title="${escapeHtml(
              conf
            )} (open in catalog)" data-open-project="${escapeHtml(row.project?.project_name || '')}" data-source-key="${escapeHtml(
              row.project?.source_key || ''
            )}" data-hit-query="${escapeHtml(hit.name)}" data-hit-path="${escapeHtml(
              hit.path || hit.name
            )}" data-hit-name="${escapeHtml(hit.name)}" data-hit-kind="${escapeHtml(hit.kind || 'file')}"><span aria-hidden="true">${
              meta.emoji
            }</span> ${escapeHtml(hit.name)}</button>`;
        return `<tr>
          <td class="sp-sd-td-check"><label class="sp-sd-hit-select"><input type="checkbox" data-sel-key="${escapeHtml(
            selKey
          )}" data-sel-url="${escapeHtml(url)}" ${checked ? 'checked' : ''}></label></td>
          <td class="sp-sd-td-name">${nameCell}</td>
          <td class="sp-sd-td-path" title="${escapeHtml(path)}">${escapeHtml(folderPath)}</td>
          <td class="sp-sd-td-type">${escapeHtml(meta.label)}</td>
          <td class="sp-sd-td-score" title="${escapeHtml(conf)}">${score}%</td>
          <td class="sp-sd-td-modified" data-ms="${ms == null ? '' : ms}" title="${escapeHtml(
          detail?.last_modified || when
        )}">${escapeHtml(when)}${age ? ` <span class="sp-sd-td-age">${escapeHtml(age)}</span>` : ''}</td>
        </tr>`;
      })
      .join('');

    return `<div class="sp-sd-table-wrap">
      <table class="sp-sd-hit-table">
        <thead>
          <tr>
            <th scope="col" class="sp-sd-td-check"></th>
            <th scope="col">${hitSortHeader('name', 'Name')}</th>
            <th scope="col">${hitSortHeader('path', 'Path')}</th>
            <th scope="col">${hitSortHeader('type', 'Type')}</th>
            <th scope="col">${hitSortHeader('score', 'Match')}</th>
            <th scope="col">${hitSortHeader('modified', 'Modified')}</th>
          </tr>
        </thead>
        <tbody>${rowsHtml}</tbody>
      </table>
    </div>`;
  };

  const projectCardHtml = (row, snapshot) => {
    const project = row.project || {};
    const name = String(project.project_name || '');
    const sourceKey = String(project.source_key || '');
    const sourceTitle = String(project.source_title || sourceKey);
    const folderUrl = folderUrlFor(row);
    const key = projectKey(project);
    const hits = Array.isArray(row._dashHits) ? row._dashHits : collectAllHits(row, snapshot);
    const filteredHits = ui.typeFilter ? hits.filter((hit) => typeBucketFor(hit).key === ui.typeFilter) : hits;
    const sortedHits = sortHitsList(row, filteredHits);
    const expanded = ui.expanded.has(key);
    const previewLimit = ui.hitsView === 'table' ? 40 : PER_PROJECT_PREVIEW;
    const visible = expanded ? sortedHits : sortedHits.slice(0, previewLimit);
    const extra = Math.max(0, sortedHits.length - visible.length);
    const badge =
      typeof api().catalogBadgeHtml === 'function'
        ? api().catalogBadgeHtml(sourceTitle, sourceKey)
        : `<span class="sp-catalog-badge">${escapeHtml(sourceTitle)}</span>`;
    const ageLabel = typeof api().formatAgeLabel === 'function' ? api().formatAgeLabel(project._modifiedMs) : '';
    const modified =
      typeof api().formatModified === 'function' ? api().formatModified(project.last_modified) : project.last_modified || '';
    const qr =
      folderUrl && typeof api().qrButtonHtml === 'function'
        ? api().qrButtonHtml(folderUrl, name, { sourceKey, catalog: sourceTitle })
        : '';
    const copy = folderUrl
      ? `<button type="button" class="button ghost-light sp-copy-link-btn" data-copy-url="${escapeHtml(
          folderUrl
        )}" data-label="📋" title="Copy SharePoint link" aria-label="Copy link for ${escapeHtml(name)}">📋</button>`
      : '';
    const openSp = folderUrl
      ? `<a class="button ghost-light" href="${escapeHtml(folderUrl)}" target="_blank" rel="noopener noreferrer" title="Open folder in SharePoint">🔗</a>`
      : '';
    const folderSel = selectionKey('folder', row);
    const conf = confidenceText(row.match);
    const hitsBody =
      ui.hitsView === 'table'
        ? hitTableHtml(row, visible)
        : visible.length
          ? visible.map((hit) => hitChipHtml(row, hit)).join('')
          : '<span class="sp-sd-empty-note">No nested file hits (folder name match)</span>';

    return `<article class="sp-sd-card" data-project-key="${escapeHtml(key)}">
      <header class="sp-sd-card-head">
        <div class="sp-sd-card-title">
          <label class="sp-sd-card-select" title="Select folder link">
            <input type="checkbox" data-sel-key="${escapeHtml(folderSel)}" data-sel-url="${escapeHtml(folderUrl)}" ${
      ui.selected.has(folderSel) ? 'checked' : ''
    }>
          </label>
          <button type="button" class="sp-sd-project-open" data-open-project="${escapeHtml(name)}" data-source-key="${escapeHtml(
      sourceKey
    )}" title="Open in catalog viewer">
            <span aria-hidden="true">📁</span>
            <span>${escapeHtml(name)}</span>
          </button>
          <div class="sp-sd-card-meta">${badge}${matchBadge(row)}</div>
        </div>
        <div class="sp-sd-card-actions">${openSp}${copy}${qr}</div>
      </header>
      <p class="sp-sd-confidence" title="${escapeHtml(conf)}"><strong>Why this matched:</strong> ${escapeHtml(conf)}</p>
      <div class="sp-sd-card-sub">
        <span>${sortedHits.length} nested match${sortedHits.length === 1 ? '' : 'es'}</span>
        ${modified ? `<span>Modified ${escapeHtml(modified)}${ageLabel ? ` · ${escapeHtml(ageLabel)}` : ''}</span>` : ''}
        ${project.modified_by ? `<span>👤 ${escapeHtml(project.modified_by)}</span>` : ''}
        ${project.person ? `<span>🙋 ${escapeHtml(project.person)}</span>` : ''}
        ${
          ui.hitsView === 'table'
            ? `<span class="sp-sd-hit-sort-hint">Files sorted by ${escapeHtml(ui.hitSortKey)} (${escapeHtml(
                ui.hitSortDir
              )})</span>`
            : ''
        }
      </div>
      <div class="sp-sd-hits${ui.hitsView === 'table' ? ' is-table' : ''}">
        ${hitsBody}
        ${
          extra
            ? `<button type="button" class="sp-sd-more" data-expand-key="${escapeHtml(key)}" aria-expanded="${
                expanded ? 'true' : 'false'
              }">${expanded ? 'Show less' : `+${extra} more`}</button>`
            : ''
        }
      </div>
    </article>`;
  };

  const collectExportRows = (snapshot) => {
    const rows = filteredRows(snapshot);
    const out = [];
    rows.forEach((row) => {
      const project = row.project || {};
      const folderUrl = folderUrlFor(row);
      const hits = Array.isArray(row._dashHits) ? row._dashHits : collectAllHits(row, snapshot);
      out.push({
        type: 'project',
        project: project.project_name || '',
        catalog: project.source_title || project.source_key || '',
        source_key: project.source_key || '',
        path: '',
        name: project.project_name || '',
        kind: 'folder',
        score: row.match?.score || '',
        confidence: confidenceText(row.match),
        url: folderUrl,
        modified: project.last_modified || '',
        modified_by: project.modified_by || '',
        created_by: project.person || '',
      });
      hits.forEach((hit) => {
        const detail = hitDetailItem(row, hit);
        out.push({
          type: 'hit',
          project: project.project_name || '',
          catalog: project.source_title || project.source_key || '',
          source_key: project.source_key || '',
          path: hit.path || '',
          name: hit.name || '',
          kind: hit.kind || 'file',
          score: hit.match?.score || row.match?.score || '',
          confidence: confidenceText(hit.match || row.match),
          url: resolveHitUrl(row, hit),
          modified: detail?.last_modified || '',
          modified_by: detail?.modified_by || '',
          created_by: detail?.person || '',
        });
      });
    });
    return out;
  };

  const csvEscape = (value) => {
    const text = String(value ?? '');
    if (/[",\n\r]/.test(text)) return `"${text.replace(/"/g, '""')}"`;
    return text;
  };

  const exportCsv = (snapshot) => {
    const rows = collectExportRows(snapshot);
    const headers = [
      'type',
      'project',
      'catalog',
      'source_key',
      'path',
      'name',
      'kind',
      'score',
      'confidence',
      'url',
      'modified',
      'modified_by',
      'created_by',
    ];
    const lines = [headers.join(',')];
    rows.forEach((row) => {
      lines.push(headers.map((key) => csvEscape(row[key])).join(','));
    });
    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const q = String(snapshot.query || 'search')
      .trim()
      .replace(/[^\w.-]+/g, '_')
      .slice(0, 40);
    a.href = url;
    a.download = `search-dashboard_${q || 'export'}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  };

  const exportPdf = (snapshot) => {
    const rows = collectExportRows(snapshot);
    const query = escapeHtml(snapshot.query || 'Search');
    const html = `<!doctype html><html><head><meta charset="utf-8"><title>Search dashboard · ${query}</title>
      <style>
        body{font-family:Segoe UI,system-ui,sans-serif;margin:24px;color:#0f172a}
        h1{margin:0 0 4px;font-size:22px} .meta{color:#64748b;margin-bottom:18px}
        table{width:100%;border-collapse:collapse;font-size:12px}
        th,td{border:1px solid #e2e8f0;padding:6px 8px;text-align:left;vertical-align:top}
        th{background:#f8fafc} a{color:#0369a1;word-break:break-all}
        .kpi{display:flex;gap:12px;flex-wrap:wrap;margin:0 0 16px}
        .kpi div{border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;min-width:90px}
        .kpi strong{display:block;font-size:18px}
      </style></head><body>
      <h1>Search dashboard · ${query}</h1>
      <p class="meta">${rows.length} rows · exported ${escapeHtml(new Date().toLocaleString())}</p>
      <div class="kpi">
        <div><span>Projects</span><strong>${snapshot.rows?.length || 0}</strong></div>
        <div><span>Nested</span><strong>${snapshot.nestedMatchTotal || 0}</strong></div>
        <div><span>Avg</span><strong>${snapshot.avg || 0}%</strong></div>
        <div><span>Best</span><strong>${snapshot.best || 0}%</strong></div>
      </div>
      <table><thead><tr>
        <th>Type</th><th>Project</th><th>Catalog</th><th>Name</th><th>Path</th><th>Score</th><th>Confidence</th><th>URL</th>
      </tr></thead><tbody>
      ${rows
        .map(
          (r) => `<tr>
          <td>${escapeHtml(r.type)}</td>
          <td>${escapeHtml(r.project)}</td>
          <td>${escapeHtml(r.catalog)}</td>
          <td>${escapeHtml(r.name)}</td>
          <td>${escapeHtml(r.path)}</td>
          <td>${escapeHtml(r.score)}</td>
          <td>${escapeHtml(r.confidence)}</td>
          <td>${r.url ? `<a href="${escapeHtml(r.url)}">${escapeHtml(r.url)}</a>` : ''}</td>
        </tr>`
        )
        .join('')}
      </tbody></table>
      <script>window.onload=()=>window.print()</script>
      </body></html>`;
    const win = window.open('', '_blank', 'noopener,noreferrer');
    if (!win) {
      window.alert('Pop-up blocked. Allow pop-ups to export PDF.');
      return;
    }
    win.document.open();
    win.document.write(html);
    win.document.close();
  };

  const copyText = async (text) => {
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch {
      /* fall through */
    }
    try {
      const area = document.createElement('textarea');
      area.value = text;
      document.body.appendChild(area);
      area.select();
      const ok = document.execCommand('copy');
      area.remove();
      return ok;
    } catch {
      return false;
    }
  };

  const collectVisibleUrls = (snapshot) => {
    if (ui.selected.size) {
      const urls = [];
      bodyEl.querySelectorAll('[data-sel-key]').forEach((input) => {
        if (!input.checked) return;
        const url = String(input.getAttribute('data-sel-url') || '').trim();
        if (url) urls.push(url);
      });
      return [...new Set(urls)];
    }
    const rows = filteredRows(snapshot);
    const urls = [];
    rows.forEach((row) => {
      const folder = folderUrlFor(row);
      if (folder) urls.push(folder);
      const hits = Array.isArray(row._dashHits) ? row._dashHits : collectAllHits(row, snapshot);
      hits.forEach((hit) => {
        const url = resolveHitUrl(row, hit);
        if (url) urls.push(url);
      });
    });
    return [...new Set(urls)];
  };

  const flashAction = (btn, ok) => {
    if (!btn) return;
    const prev = btn.textContent;
    btn.textContent = ok ? '✓ Done' : '! Failed';
    window.setTimeout(() => {
      btn.textContent = prev;
    }, 1200);
  };

  const dashboardUiState = () => ({
    typeFilter: ui.typeFilter,
    catalogFilter: ui.catalogFilter,
    personFilter: ui.personFilter,
    sortBy: ui.sortBy,
    groupBy: ui.groupBy,
    hitsView: ui.hitsView,
    hitSortKey: ui.hitSortKey,
    hitSortDir: ui.hitSortDir,
  });

  const applyDashboardUi = (saved, { persist = true } = {}) => {
    if (!saved || typeof saved !== 'object') return;
    if (Object.prototype.hasOwnProperty.call(saved, 'typeFilter')) {
      ui.typeFilter = String(saved.typeFilter || '');
    }
    if (Object.prototype.hasOwnProperty.call(saved, 'catalogFilter')) {
      ui.catalogFilter = String(saved.catalogFilter || '');
    }
    if (Object.prototype.hasOwnProperty.call(saved, 'personFilter')) {
      ui.personFilter = String(saved.personFilter || '');
    }
    if (SORT_OPTIONS.some((o) => o.key === saved.sortBy)) ui.sortBy = saved.sortBy;
    if (GROUP_OPTIONS.some((o) => o.key === saved.groupBy) || saved.groupBy === '') {
      ui.groupBy = saved.groupBy || '';
    }
    if (saved.hitsView === 'chips' || saved.hitsView === 'table') {
      ui.hitsView = saved.hitsView;
    }
    if (HIT_SORT_KEYS.has(saved.hitSortKey)) ui.hitSortKey = saved.hitSortKey;
    if (saved.hitSortDir === 'asc' || saved.hitSortDir === 'desc') {
      ui.hitSortDir = saved.hitSortDir;
    }
    if (persist) persistDashboardUi();
  };

  const renderBody = (snapshot, kickFetch = true) => {
    if (!snapshot) {
      bodyEl.innerHTML = '<p class="panel-help">Run a search to open the dashboard.</p>';
      return;
    }
    const rows = filteredRows(snapshot);
    const groups = groupedRows(rows, snapshot);
    let renderedHits = 0;
    const sections = [];
    for (const group of groups) {
      const cards = [];
      for (const row of group.rows) {
        const hits = Array.isArray(row._dashHits) ? row._dashHits : collectAllHits(row, snapshot);
        if (renderedHits >= MAX_RENDER_HITS && cards.length) break;
        cards.push(projectCardHtml(row, snapshot));
        renderedHits += hits.length;
      }
      if (!cards.length) continue;
      sections.push(
        `${
          group.label
            ? `<div class="sp-sd-group-head"><h5>${escapeHtml(group.label)}</h5><span>${group.rows.length}</span></div>`
            : ''
        }<div class="sp-sd-cards">${cards.join('')}</div>`
      );
    }
    const truncated = rows.length > sections.reduce((n, _s, i) => n + (groups[i]?.rows.length || 0), 0);
    const queryLabel = String(snapshot.query || '').trim() || 'Search';
    if (titleEl) titleEl.textContent = queryLabel;
    if (subEl) {
      subEl.innerHTML = `${rows.length} project${rows.length === 1 ? '' : 's'} · ${
        snapshot.nestedMatchTotal || 0
      } nested matches · ${escapeHtml(
        snapshot.deep || snapshot.matchScope === 'files' ? 'Deep files on' : 'Folder names only'
      )}${snapshot.ready ? ' · <span class="sp-live-pill">⚡ Live search</span>' : ''}${
        ui.personFilter ? ` · Person: ${escapeHtml(ui.personFilter)}` : ''
      }`;
    }

    bodyEl.innerHTML = `
      <p class="sp-sd-loading panel-help" ${ui.loadingUrls ? '' : 'hidden'}>Resolving SharePoint links for matched files…</p>
      ${kpiHtml(snapshot)}
      ${toolbarHtml()}
      ${breakdownHtml(snapshot, rows)}
      <div class="sp-sd-results-head">
        <h4>Matched projects &amp; files</h4>
        <span class="sp-sd-results-meta">${rows.length} shown${truncated ? '' : ''}${
      renderedHits > MAX_RENDER_HITS ? ` · first ${MAX_RENDER_HITS} file links` : ''
    }</span>
      </div>
      ${sections.join('') || '<p class="panel-help">No projects match the current dashboard filters.</p>'}
    `;

    bindBodyEvents(snapshot);
    if (typeof api().bindCopyLinkButtons === 'function') api().bindCopyLinkButtons(bodyEl);
    if (typeof api().bindQrButtons === 'function') api().bindQrButtons(bodyEl);
    if (kickFetch && !ui.loadingUrls) loadDetailUrls(snapshot.rows || []);
  };

  const bindBodyEvents = (snapshot) => {
    bodyEl.querySelectorAll('[data-catalog-filter]').forEach((btn) => {
      btn.addEventListener('click', () => {
        ui.catalogFilter = btn.getAttribute('data-catalog-filter') || '';
        renderBody(snapshot, false);
      });
    });
    bodyEl.querySelectorAll('[data-type-filter]').forEach((btn) => {
      btn.addEventListener('click', () => {
        ui.typeFilter = btn.getAttribute('data-type-filter') || '';
        renderBody(snapshot, false);
      });
    });
    bodyEl.querySelectorAll('[data-person-filter]').forEach((btn) => {
      btn.addEventListener('click', () => {
        ui.personFilter = btn.getAttribute('data-person-filter') || '';
        renderBody(snapshot, false);
      });
    });
    bodyEl.querySelector('[data-sd-sort]')?.addEventListener('change', (event) => {
      ui.sortBy = event.target.value || 'score';
      persistDashboardUi();
      renderBody(snapshot, false);
    });
    bodyEl.querySelector('[data-sd-group]')?.addEventListener('change', (event) => {
      ui.groupBy = event.target.value || '';
      persistDashboardUi();
      renderBody(snapshot, false);
    });
    bodyEl.querySelectorAll('[data-hits-view]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const next = btn.getAttribute('data-hits-view') === 'table' ? 'table' : 'chips';
        ui.hitsView = next;
        if (next === 'table' && !ui.hitSortKey) {
          ui.hitSortKey = 'modified';
          ui.hitSortDir = 'desc';
        }
        persistDashboardUi();
        renderBody(snapshot, false);
      });
    });
    bodyEl.querySelectorAll('[data-hit-sort]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const key = btn.getAttribute('data-hit-sort') || 'modified';
        if (ui.hitSortKey === key) {
          ui.hitSortDir = ui.hitSortDir === 'asc' ? 'desc' : 'asc';
        } else {
          ui.hitSortKey = key;
          ui.hitSortDir = key === 'name' || key === 'path' || key === 'type' ? 'asc' : 'desc';
        }
        ui.hitsView = 'table';
        persistDashboardUi();
        renderBody(snapshot, false);
      });
    });
    bodyEl.querySelectorAll('[data-expand-key]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const key = btn.getAttribute('data-expand-key') || '';
        if (ui.expanded.has(key)) ui.expanded.delete(key);
        else ui.expanded.add(key);
        renderBody(snapshot, false);
      });
    });
    bodyEl.querySelectorAll('[data-open-project]').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        const name = btn.getAttribute('data-open-project') || '';
        const sourceKey = btn.getAttribute('data-source-key') || '';
        const hitQuery = btn.getAttribute('data-hit-query') || '';
        if (typeof api().openProject === 'function') api().openProject(name, sourceKey, hitQuery);
      });
    });
    bodyEl.querySelectorAll('[data-sel-key]').forEach((input) => {
      input.addEventListener('change', () => {
        const key = input.getAttribute('data-sel-key') || '';
        if (input.checked) ui.selected.add(key);
        else ui.selected.delete(key);
        const countEl = bodyEl.querySelector('[data-sd-sel-count]');
        if (countEl) countEl.textContent = `${ui.selected.size} selected`;
      });
    });
    bodyEl.querySelectorAll('[data-sd-action]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const action = btn.getAttribute('data-sd-action');
        if (action === 'export-csv') {
          exportCsv(snapshot);
          flashAction(btn, true);
        } else if (action === 'export-pdf') {
          exportPdf(snapshot);
          flashAction(btn, true);
        } else if (action === 'copy-links') {
          const urls = collectVisibleUrls(snapshot);
          if (!urls.length) {
            window.alert('No SharePoint URLs available yet. Wait for links to resolve, or select items with URLs.');
            return;
          }
          const ok = await copyText(urls.join('\n'));
          flashAction(btn, ok);
        } else if (action === 'save-snap') {
          const entry = api().saveNamedSearch?.({
            kind: 'dashboard',
            dashboardUi: dashboardUiState(),
          });
          flashAction(btn, !!entry);
        } else if (action === 'clear-sel') {
          ui.selected.clear();
          renderBody(snapshot, false);
        }
      });
    });
  };

  const openDashboard = (savedUi = null) => {
    const snapshot = typeof api().getLiveSearchSnapshot === 'function' ? api().getLiveSearchSnapshot() : null;
    if (!snapshot || !snapshot.searching) return;
    allowClose = false;
    currentSnapshot = snapshot;
    // Query-specific filters reset each open; layout controls stay from localStorage.
    ui.typeFilter = '';
    ui.catalogFilter = '';
    ui.personFilter = '';
    ui.selected = new Set();
    ui.expanded = new Set();
    ui.urlMap = new Map();
    ui.hitCache = new Map();
    ui.detailMeta = new Map();
    ui.loadingUrls = false;
    applyDashboardUi(readPersistedControls(), { persist: false });
    if (savedUi) applyDashboardUi(savedUi, { persist: true });
    renderBody(snapshot, true);
    if (typeof dialog.showModal === 'function') {
      if (!dialog.open) dialog.showModal();
    } else {
      dialog.setAttribute('open', '');
    }
    dialog.__spPrepareWorkspace?.();
  };

  document.addEventListener('click', (event) => {
    const btn = event.target.closest?.('#sharepoint-search-dash-open');
    if (!btn) return;
    event.preventDefault();
    openDashboard();
  });

  statsEl?.addEventListener('click', (event) => {
    const btn = event.target.closest?.('#sharepoint-search-dash-open');
    if (!btn) return;
    event.preventDefault();
    openDashboard();
  });

  window.RiskRegisterSharePoint = Object.assign(window.RiskRegisterSharePoint || {}, {
    openSearchDashboard: openDashboard,
  });
})();
