(() => {
  const dialog = document.getElementById('sharepoint-search-dash-dialog');
  const bodyEl = document.getElementById('sharepoint-search-dash-body');
  const titleEl = document.getElementById('sharepoint-search-dash-title');
  const subEl = document.getElementById('sharepoint-search-dash-sub');
  const closeBtn = document.getElementById('sharepoint-search-dash-close');
  const statsEl = document.getElementById('sharepoint-search-stats');
  if (!dialog || !bodyEl) return;

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
  const TYPE_BUCKETS = [
    { key: 'pdf', label: 'PDF', match: (ext, kind) => kind !== 'folder' && ext === 'pdf' },
    { key: 'excel', label: 'Excel', match: (ext, kind) => kind !== 'folder' && ['xls', 'xlsx', 'xlsm', 'csv'].includes(ext) },
    { key: 'email', label: 'Email', match: (ext, kind) => kind !== 'folder' && ['msg', 'eml'].includes(ext) },
    { key: 'visio', label: 'Visio', match: (ext, kind) => kind !== 'folder' && ['vsd', 'vsdx'].includes(ext) },
    { key: 'word', label: 'Word', match: (ext, kind) => kind !== 'folder' && ['doc', 'docx', 'rtf'].includes(ext) },
    { key: 'folder', label: 'Folders', match: (_ext, kind) => kind === 'folder' },
    { key: 'other', label: 'Other', match: () => true },
  ];

  /** @type {{ typeFilter: string, catalogFilter: string, expanded: Set<string>, urlMap: Map<string, string>, loadingUrls: boolean, abort: AbortController|null, hitCache: Map<string, array> }} */
  const ui = {
    typeFilter: '',
    catalogFilter: '',
    expanded: new Set(),
    urlMap: new Map(),
    loadingUrls: false,
    abort: null,
    hitCache: new Map(),
  };

  /** @type {object|null} */
  let currentSnapshot = null;

  if (typeof api().bindWorkspaceDialog === 'function') {
    api().bindWorkspaceDialog(dialog);
  }

  let allowClose = false;

  const close = () => {
    allowClose = true;
    if (typeof dialog.close === 'function' && dialog.open) dialog.close();
    else dialog.removeAttribute('open');
  };

  closeBtn?.addEventListener('click', (event) => {
    event.preventDefault();
    close();
  });

  // Stay open until the Close (✕) button is used — ignore backdrop / Escape dismiss.
  dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
  });

  dialog.addEventListener('close', () => {
    if (!allowClose && currentSnapshot) {
      // Re-open if something else tried to dismiss the dashboard.
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

  const projectKey = (project) =>
    `${String(project?.source_key || '')}::${String(project?.project_name || '')}`;

  const hitLookupKey = (sourceKey, projectName, path, name) =>
    `${String(sourceKey || '')}::${String(projectName || '')}::${String(path || name || '')
      .trim()
      .toLowerCase()}`;

  const parseQueryWords = (snapshot) => {
    const Fuzzy = window.FuzzySearch;
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
      map.set(hitLookupKey(sourceKey, projectName, path.toLowerCase(), name), url);
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
          if (folderUrl) {
            ui.urlMap.set(hitLookupKey(sourceKey, name, '__folder__', name), folderUrl);
          }
          buildUrlIndex(name, sourceKey, items).forEach((url, key) => ui.urlMap.set(key, url));
          paintProjectLinks(row);
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

  const paintProjectLinks = (row) => {
    const card = bodyEl.querySelector(`[data-project-key="${CSS.escape(projectKey(row.project))}"]`);
    if (!card) return;
    card.querySelectorAll('[data-hit-path]').forEach((el) => {
      const path = el.getAttribute('data-hit-path') || '';
      const name = el.getAttribute('data-hit-name') || '';
      const url = resolveHitUrl(row, { path, name });
      if (!url) return;
      if (el.tagName === 'A') {
        el.setAttribute('href', url);
        el.classList.remove('sp-sd-hit--fallback');
        el.setAttribute('target', '_blank');
        el.setAttribute('rel', 'noopener noreferrer');
      } else if (el.tagName === 'BUTTON') {
        const meta = resolveMeta({ name, item_type: el.getAttribute('data-hit-kind') || 'file' });
        const pathLabel = path && path !== name ? path : '';
        const anchor = document.createElement('a');
        anchor.className = 'sp-sd-hit';
        anchor.href = url;
        anchor.target = '_blank';
        anchor.rel = 'noopener noreferrer';
        anchor.title = pathLabel ? `${name} — ${pathLabel}` : name;
        anchor.setAttribute('data-hit-path', path);
        anchor.setAttribute('data-hit-name', name);
        anchor.setAttribute('data-hit-kind', el.getAttribute('data-hit-kind') || 'file');
        anchor.innerHTML = `<span aria-hidden="true">${meta.emoji}</span><span class="sp-sd-hit-name">${escapeHtml(name)}</span>${
          pathLabel ? `<span class="sp-sd-hit-path">${escapeHtml(pathLabel)}</span>` : ''
        }`;
        el.replaceWith(anchor);
      }
    });
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
        .forEach((name) => {
          counts.set(name, (counts.get(name) || 0) + 1);
        });
    });
    return [...counts.entries()]
      .map(([name, count]) => ({ name, count }))
      .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name))
      .slice(0, 8);
  };

  const filteredRows = (snapshot) => {
    let rows = snapshot.rows || [];
    if (ui.catalogFilter) {
      rows = rows.filter((row) => String(row.project?.source_key || '') === ui.catalogFilter);
    }
    if (ui.typeFilter) {
      rows = rows
        .map((row) => {
          const hits = collectAllHits(row, snapshot).filter((hit) => typeBucketFor(hit).key === ui.typeFilter);
          return { ...row, _dashHits: hits };
        })
        .filter((row) => row._dashHits.length > 0 || ui.typeFilter === '');
      if (ui.typeFilter === 'folder') {
        /* keep projects that matched via folder hits only */
      }
    }
    return rows;
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

  const chipBar = (items, activeKey, attr, allLabel) => {
    if (!items.length) return '';
    const chips = [
      `<button type="button" class="sp-sd-chip${!activeKey ? ' is-active' : ''}" data-${attr}="" aria-pressed="${!activeKey ? 'true' : 'false'}">${escapeHtml(allLabel)}</button>`,
      ...items.map((item) => {
        const key = item.key || item.name;
        const label = item.label || item.name || item.title;
        const active = activeKey === key;
        return `<button type="button" class="sp-sd-chip${active ? ' is-active' : ''}" data-${attr}="${escapeHtml(key)}" aria-pressed="${active ? 'true' : 'false'}">${escapeHtml(label)} <span class="sp-sd-chip-count">${item.count}</span></button>`;
      }),
    ];
    return `<div class="sp-sd-chips" role="group">${chips.join('')}</div>`;
  };

  const breakdownHtml = (snapshot) => {
    const catalogs = catalogBreakdown(snapshot.rows || []);
    const types = typeBreakdown(snapshot.rows || [], snapshot);
    const freshness = freshnessBreakdown(snapshot.rows || []);
    const people = peopleBreakdown(snapshot.rows || []);
    return `<div class="sp-sd-breakdowns">
      <section class="sp-sd-panel">
        <h4>Catalogs</h4>
        ${chipBar(catalogs.map((c) => ({ key: c.key, label: c.title, count: c.count })), ui.catalogFilter, 'catalog-filter', 'All catalogs')}
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
            return `<div class="sp-sd-bar-row"><span class="sp-sd-bar-label">${escapeHtml(item.label)}</span><span class="sp-sd-bar-track"><span class="sp-sd-bar-fill sp-sd-bar-fill--${escapeHtml(item.key)}" style="width:${pct}%"></span></span><strong>${item.count}</strong></div>`;
          })
          .join('')}</div>
      </section>
      <section class="sp-sd-panel">
        <h4>People</h4>
        <ul class="sp-sd-people">${
          people.length
            ? people
                .map((p) => `<li><span class="sp-sd-person-name">${escapeHtml(p.name)}</span><span class="sp-sd-person-count">${p.count}</span></li>`)
                .join('')
            : '<li class="sp-sd-empty-note">No people on these matches</li>'
        }</ul>
      </section>
    </div>`;
  };

  const matchBadge = (row) => {
    if (typeof api().scoreBadgeHtml === 'function') {
      return api().scoreBadgeHtml(row.match, row.project);
    }
    const score = Number(row.match?.score || 0);
    const tone = score >= 90 ? 'high' : score >= 75 ? 'mid' : 'low';
    return `<span class="sp-match-badge sp-match-badge--${tone}">${score}%</span>`;
  };

  const hitChipHtml = (row, hit) => {
    const url = resolveHitUrl(row, hit);
    const meta = resolveMeta({ name: hit.name, item_type: hit.kind === 'folder' ? 'folder' : 'file' });
    const path = hit.path && hit.path !== hit.name ? hit.path : '';
    const title = path ? `${hit.name} — ${path}` : hit.name;
    const inner = `<span aria-hidden="true">${meta.emoji}</span><span class="sp-sd-hit-name">${escapeHtml(hit.name)}</span>${
      path ? `<span class="sp-sd-hit-path">${escapeHtml(path)}</span>` : ''
    }`;
    if (url) {
      return `<a class="sp-sd-hit" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" title="${escapeHtml(title)}" data-hit-path="${escapeHtml(hit.path || hit.name)}" data-hit-name="${escapeHtml(hit.name)}" data-hit-kind="${escapeHtml(hit.kind || 'file')}">${inner}</a>`;
    }
    return `<button type="button" class="sp-sd-hit sp-sd-hit--fallback" title="${escapeHtml(title)} (open in catalog)" data-open-project="${escapeHtml(row.project?.project_name || '')}" data-source-key="${escapeHtml(row.project?.source_key || '')}" data-hit-query="${escapeHtml(hit.name)}" data-hit-path="${escapeHtml(hit.path || hit.name)}" data-hit-name="${escapeHtml(hit.name)}" data-hit-kind="${escapeHtml(hit.kind || 'file')}">${inner}</button>`;
  };

  const projectCardHtml = (row, snapshot) => {
    const project = row.project || {};
    const name = String(project.project_name || '');
    const sourceKey = String(project.source_key || '');
    const sourceTitle = String(project.source_title || sourceKey);
    const folderUrl =
      String(project.folder_url || '').trim() ||
      ui.urlMap.get(hitLookupKey(sourceKey, name, '__folder__', name)) ||
      '';
    const key = projectKey(project);
    const hits = Array.isArray(row._dashHits) ? row._dashHits : collectAllHits(row, snapshot);
    const filteredHits = ui.typeFilter ? hits.filter((hit) => typeBucketFor(hit).key === ui.typeFilter) : hits;
    const expanded = ui.expanded.has(key);
    const visible = expanded ? filteredHits : filteredHits.slice(0, PER_PROJECT_PREVIEW);
    const extra = Math.max(0, filteredHits.length - visible.length);
    const badge =
      typeof api().catalogBadgeHtml === 'function'
        ? api().catalogBadgeHtml(sourceTitle, sourceKey)
        : `<span class="sp-catalog-badge">${escapeHtml(sourceTitle)}</span>`;
    const ageLabel =
      typeof api().formatAgeLabel === 'function' ? api().formatAgeLabel(project._modifiedMs) : '';
    const modified =
      typeof api().formatModified === 'function' ? api().formatModified(project.last_modified) : project.last_modified || '';
    const qr =
      folderUrl && typeof api().qrButtonHtml === 'function'
        ? api().qrButtonHtml(folderUrl, name, { sourceKey, catalog: sourceTitle })
        : '';
    const copy = folderUrl
      ? `<button type="button" class="button ghost-light sp-copy-link-btn" data-copy-url="${escapeHtml(folderUrl)}" data-label="📋" title="Copy SharePoint link" aria-label="Copy link for ${escapeHtml(name)}">📋</button>`
      : '';
    const openSp = folderUrl
      ? `<a class="button ghost-light" href="${escapeHtml(folderUrl)}" target="_blank" rel="noopener noreferrer" title="Open folder in SharePoint">🔗</a>`
      : '';

    return `<article class="sp-sd-card" data-project-key="${escapeHtml(key)}">
      <header class="sp-sd-card-head">
        <div class="sp-sd-card-title">
          <button type="button" class="sp-sd-project-open" data-open-project="${escapeHtml(name)}" data-source-key="${escapeHtml(sourceKey)}" title="Open in catalog viewer">
            <span aria-hidden="true">📁</span>
            <span>${escapeHtml(name)}</span>
          </button>
          <div class="sp-sd-card-meta">${badge}${matchBadge(row)}</div>
        </div>
        <div class="sp-sd-card-actions">${openSp}${copy}${qr}</div>
      </header>
      <div class="sp-sd-card-sub">
        <span>${filteredHits.length} nested match${filteredHits.length === 1 ? '' : 'es'}</span>
        ${modified ? `<span>Modified ${escapeHtml(modified)}${ageLabel ? ` · ${escapeHtml(ageLabel)}` : ''}</span>` : ''}
        ${project.modified_by ? `<span>👤 ${escapeHtml(project.modified_by)}</span>` : ''}
        ${project.person ? `<span>🙋 ${escapeHtml(project.person)}</span>` : ''}
      </div>
      <div class="sp-sd-hits">
        ${
          visible.length
            ? visible.map((hit) => hitChipHtml(row, hit)).join('')
            : '<span class="sp-sd-empty-note">No nested file hits (folder name match)</span>'
        }
        ${
          extra
            ? `<button type="button" class="sp-sd-more" data-expand-key="${escapeHtml(key)}" aria-expanded="${expanded ? 'true' : 'false'}">${expanded ? 'Show less' : `+${extra} more`}</button>`
            : ''
        }
      </div>
    </article>`;
  };

  const renderBody = (snapshot, kickFetch = true) => {
    if (!snapshot) {
      bodyEl.innerHTML = '<p class="panel-help">Run a search to open the dashboard.</p>';
      return;
    }
    const rows = filteredRows(snapshot);
    let renderedHits = 0;
    const cards = [];
    for (const row of rows) {
      const hits = Array.isArray(row._dashHits) ? row._dashHits : collectAllHits(row, snapshot);
      const nextCount = renderedHits + hits.length;
      if (renderedHits >= MAX_RENDER_HITS && cards.length) break;
      cards.push(projectCardHtml(row, snapshot));
      renderedHits = nextCount;
    }
    const truncated = rows.length > cards.length;
    const queryLabel = String(snapshot.query || '').trim() || 'Search';
    if (titleEl) titleEl.textContent = queryLabel;
    if (subEl) {
      subEl.innerHTML = `${rows.length} project${rows.length === 1 ? '' : 's'} · ${snapshot.nestedMatchTotal || 0} nested matches · ${escapeHtml(
        snapshot.deep || snapshot.matchScope === 'files' ? 'Deep files on' : 'Folder names only'
      )}${snapshot.ready ? ' · <span class="sp-live-pill">⚡ Live search</span>' : ''}`;
    }

    bodyEl.innerHTML = `
      <p class="sp-sd-loading panel-help" ${ui.loadingUrls ? '' : 'hidden'}>Resolving SharePoint links for matched files…</p>
      ${kpiHtml(snapshot)}
      ${breakdownHtml(snapshot)}
      <div class="sp-sd-results-head">
        <h4>Matched projects &amp; files</h4>
        <span class="sp-sd-results-meta">${cards.length} shown${truncated ? ` of ${rows.length}` : ''}${
      renderedHits > MAX_RENDER_HITS ? ` · first ${MAX_RENDER_HITS} file links` : ''
    }</span>
      </div>
      <div class="sp-sd-cards">${cards.join('') || '<p class="panel-help">No projects match the current dashboard filters.</p>'}</div>
    `;

    bindBodyEvents(snapshot);
    if (typeof api().bindCopyLinkButtons === 'function') api().bindCopyLinkButtons(bodyEl);
    if (typeof api().bindQrButtons === 'function') api().bindQrButtons(bodyEl);
    if (kickFetch && !ui.loadingUrls) {
      loadDetailUrls(snapshot.rows || []);
    }
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
        if (typeof api().openProject === 'function') {
          api().openProject(name, sourceKey, hitQuery);
        }
      });
    });
  };

  const openDashboard = () => {
    const snapshot = typeof api().getLiveSearchSnapshot === 'function' ? api().getLiveSearchSnapshot() : null;
    if (!snapshot || !snapshot.searching) return;
    allowClose = false;
    currentSnapshot = snapshot;
    ui.typeFilter = '';
    ui.catalogFilter = '';
    ui.expanded = new Set();
    ui.urlMap = new Map();
    ui.hitCache = new Map();
    ui.loadingUrls = false;
    renderBody(snapshot, true);
    if (typeof dialog.showModal === 'function') {
      if (!dialog.open) dialog.showModal();
    } else {
      dialog.setAttribute('open', '');
    }
  };

  // Event delegation: stats button is rebuilt on every search render.
  document.addEventListener('click', (event) => {
    const btn = event.target.closest?.('#sharepoint-search-dash-open');
    if (!btn) return;
    event.preventDefault();
    openDashboard();
  });

  // Also bind when stats strip already exists.
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
