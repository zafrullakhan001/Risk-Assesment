(() => {
  const root = document.getElementById('sharepoint-owner-dash');
  const body = document.getElementById('sp-owner-dash-body');
  const yearSelect = document.getElementById('sp-owner-year');
  const scopesRoot = document.getElementById('sp-owner-scopes');
  if (!root || !body) return;

  const publicShare = root.getAttribute('data-public') === '1';
  const STORAGE_KEY = publicShare ? 'riskregister_sp_public_owner_scopes' : 'riskregister_sp_owner_scopes';
  const SHELL_KEY = 'riskregister_sp_owner_dash_open';

  const ownerApiUrl = (action, extra = {}) => {
    const base = (root.getAttribute('data-api-base') || 'sharepoint.php').trim() || 'sharepoint.php';
    const token = (root.getAttribute('data-share-token') || '').trim();
    const params = new URLSearchParams();
    params.set('action', String(action || ''));
    if (token) params.set('t', token);
    Object.entries(extra || {}).forEach(([key, value]) => {
      if (value === undefined || value === null || String(value) === '') return;
      params.set(key, String(value));
    });
    return `${base}?${params.toString()}`;
  };

  const openOwnerProject = (btn) => {
    const name = btn.getAttribute('data-project-name') || '';
    const source = btn.getAttribute('data-source-key') || '';
    const folderUrl = btn.getAttribute('data-folder-url') || '';
    if (typeof window.RiskRegisterSharePoint?.openProject === 'function') {
      window.RiskRegisterSharePoint.openProject(name, source);
      return;
    }
    if (folderUrl) {
      window.open(folderUrl, '_blank', 'noopener,noreferrer');
    }
  };
  const MONTH_LABELS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const TOP_SERIES = 8;
  const HEATMAP_OWNERS = 12;

  const escapeHtml = (value) =>
    String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

  const escapeAttr = (value) => escapeHtml(value).replace(/\r?\n/g, '&#10;');

  const highlightQuery = (text) => {
    const raw = String(text ?? '');
    const query = state.ownerQuery.trim();
    if (!query) return escapeHtml(raw);
    const lower = raw.toLowerCase();
    const needle = query.toLowerCase();
    const idx = lower.indexOf(needle);
    if (idx < 0) return escapeHtml(raw);
    return `${escapeHtml(raw.slice(0, idx))}<mark class="sp-od-mark">${escapeHtml(raw.slice(idx, idx + query.length))}</mark>${escapeHtml(raw.slice(idx + query.length))}`;
  };

  const ownerColor = (hue, alpha = 1) =>
    `hsla(${Number(hue) || 200}, 62%, 46%, ${alpha})`;

  const formatMonth = (key) => {
    const match = /^(\d{4})-(\d{2})$/.exec(String(key || ''));
    if (!match) return String(key || '');
    const month = MONTH_LABELS[Number(match[2]) - 1] || match[2];
    return `${month} ${match[1]}`;
  };

  const formatQuarter = (key) => {
    const match = /^(\d{4})-Q([1-4])$/.exec(String(key || ''));
    if (!match) return String(key || '');
    return `Q${match[2]} ${match[1]}`;
  };

  const formatPeriod = (key, grain) => {
    if (grain === 'year') return String(key || '');
    if (grain === 'quarter') return formatQuarter(key);
    return formatMonth(key);
  };

  const formatDay = (iso) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
    if (!match) return iso ? String(iso) : 'Unknown date';
    const month = MONTH_LABELS[Number(match[2]) - 1] || match[2];
    return `${month} ${Number(match[3])}, ${match[1]}`;
  };

  const formatBytes = (value) => {
    const n = Number(value) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    if (n < 1024 * 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} MB`;
    return `${(n / (1024 * 1024 * 1024)).toFixed(1)} GB`;
  };

  const formatPct = (value) => `${Math.round((Number(value) || 0) * 100)}%`;

  const formatSignedPct = (value) => {
    const n = Math.round((Number(value) || 0) * 100);
    if (n > 0) return `+${n}%`;
    return `${n}%`;
  };

  const currentYear = String(new Date().getFullYear());
  const dormantCutoff = (() => {
    const d = new Date();
    d.setMonth(d.getMonth() - 12);
    return d.toISOString().slice(0, 10);
  })();

  const payloadOwner = (key) => (state.data?.owners || []).find((owner) => owner.key === key) || null;

  const periodOf = (project, grain) => {
    if (grain === 'year') return project.year || '';
    if (grain === 'quarter') return project.quarter || '';
    return project.month || '';
  };

  const TOOLTIP_NAME_CAP = 8;

  const projectsForOwnerPeriod = (view, ownerKey, period) => {
    const owner = (view.owners || []).find((row) => row.key === ownerKey);
    if (!owner) return [];
    return (owner.projects || [])
      .filter((project) => periodOf(project, state.grain) === period)
      .slice()
      .sort((a, b) => String(b.date_created || '').localeCompare(String(a.date_created || '')));
  };

  const projectsForOthersPeriod = (view, period, topKeys) => {
    const keys = topKeys instanceof Set ? topKeys : new Set(topKeys || []);
    const rows = [];
    (view.owners || []).forEach((owner) => {
      if (keys.has(owner.key)) return;
      (owner.projects || []).forEach((project) => {
        if (periodOf(project, state.grain) === period) {
          rows.push({ ...project, owner_key: owner.key, owner_name: owner.name, hue: owner.hue });
        }
      });
    });
    return rows.sort((a, b) => String(b.date_created || '').localeCompare(String(a.date_created || '')));
  };

  const tooltipForBucket = (label, period, projects) => {
    const count = projects.length;
    const lines = [`${label} · ${formatPeriod(period, state.grain)} · ${count}`];
    const shown = projects.slice(0, TOOLTIP_NAME_CAP);
    shown.forEach((project) => {
      lines.push(project.project_name || 'Untitled project');
    });
    if (count > TOOLTIP_NAME_CAP) {
      lines.push(`and ${count - TOOLTIP_NAME_CAP} more…`);
    }
    if (count > 0) lines.push('Click to open list');
    return lines.join('\n');
  };

  const renderProjectListItems = (projects, { showOwner = false } = {}) =>
    projects
      .map((project) => {
        const meta = [
          showOwner ? project.owner_name || '' : '',
          project.source_title || '',
          project.date_created ? formatDay(project.date_created) : '',
          project.last_modified && project.last_modified !== project.date_created ? `active ${formatDay(project.last_modified)}` : '',
          project.item_count ? `${project.item_count} items` : '',
        ]
          .filter(Boolean)
          .join(' · ');
        return `<li>
          <button type="button" class="sp-od-project" data-project-name="${escapeHtml(project.project_name)}" data-source-key="${escapeHtml(project.source_key)}" data-folder-url="${escapeHtml(project.folder_url || '')}">
            <strong>${highlightQuery(project.project_name)}</strong>
            <span>${escapeHtml(meta)}</span>
          </button>
          ${assessmentBadge(project)}
          ${
            project.folder_url
              ? `<a class="sp-od-open-sp" href="${escapeHtml(project.folder_url)}" target="_blank" rel="noopener noreferrer" title="Open in SharePoint">🔗</a>`
              : ''
          }
        </li>`;
      })
      .join('');

  const cellDialog = document.getElementById('sp-od-cell-dialog');
  const cellDialogTitle = document.getElementById('sp-od-cell-dialog-title');
  const cellDialogSub = document.getElementById('sp-od-cell-dialog-sub');
  const cellDialogList = document.getElementById('sp-od-cell-dialog-list');

  const closeCellDialog = () => {
    if (cellDialog?.open) cellDialog.close();
  };

  const openCellDialog = ({ label, period, projects, showOwner = false }) => {
    if (!cellDialog || !cellDialogTitle || !cellDialogSub || !cellDialogList) return;
    const count = projects.length;
    cellDialogTitle.textContent = `${label} · ${formatPeriod(period, state.grain)}`;
    cellDialogSub.textContent = `${count} project folder${count === 1 ? '' : 's'}`;
    cellDialogList.innerHTML = count
      ? renderProjectListItems(projects, { showOwner })
      : `<li class="sp-od-cell-empty">No project folders in this period.</li>`;
    cellDialogList.querySelectorAll('.sp-od-project').forEach((btn) => {
      btn.addEventListener('click', () => openOwnerProject(btn));
    });
    if (!cellDialog.open) cellDialog.showModal();
  };

  document.getElementById('sp-od-cell-dialog-close')?.addEventListener('click', () => closeCellDialog());
  cellDialog?.addEventListener('click', (event) => {
    if (event.target === cellDialog) closeCellDialog();
  });

  const availableSources = (() => {
    try {
      const parsed = JSON.parse(root.getAttribute('data-sources') || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  })();

  const titleByKey = Object.fromEntries(
    availableSources.map((src) => [String(src.source_key || ''), String(src.title || src.source_key || '')])
  );

  const readSavedScopes = () => {
    const params = new URLSearchParams(window.location.search);
    const urlSources = params.get('osources');
    if (urlSources === 'all') {
      const all = availableSources.map((src) => String(src.source_key || '')).filter(Boolean);
      if (all.length) return all;
    } else if (urlSources) {
      const fromUrl = urlSources
        .split(',')
        .map((key) => key.trim())
        .filter((key) => titleByKey[key]);
      if (fromUrl.length) return fromUrl;
    }

    const urlSource = (params.get('source') || root.getAttribute('data-active-source') || '').trim();
    if (publicShare && urlSource && titleByKey[urlSource]) {
      return [urlSource];
    }

    try {
      const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
      if (Array.isArray(raw) && raw.length) {
        const valid = raw.map(String).filter((key) => titleByKey[key]);
        if (valid.length) return valid;
      }
    } catch {
      /* ignore */
    }

    if (publicShare && urlSource) return [urlSource];
    const checks = scopesRoot
      ? Array.from(scopesRoot.querySelectorAll('.sp-owner-scope-check:checked')).map((el) => el.value)
      : [];
    if (checks.length) return checks;
    return availableSources.map((src) => String(src.source_key || '')).filter(Boolean);
  };

  const state = {
    data: null,
    loading: false,
    error: '',
    grain: 'month',
    year: 'all',
    ownerKey: '',
    ownerQuery: '',
    sourceKeys: readSavedScopes(),
    sort: 'projects',
    chip: '',
    heatAll: false,
    compareKeys: [],
  };

  const urlSyncEnabled = () =>
    root.getAttribute('data-solo') === '1' ||
    publicShare ||
    new URL(window.location.href).searchParams.get('view') === 'owners';

  const applyUrlState = () => {
    if (!urlSyncEnabled()) return;
    const params = new URLSearchParams(window.location.search);
    const grain = params.get('grain');
    if (grain === 'month' || grain === 'quarter' || grain === 'year') state.grain = grain;
    const year = params.get('oyear');
    if (year) state.year = year;
    const owner = params.get('owner');
    if (owner) state.ownerKey = owner;
    const query = params.get('oq');
    if (query) {
      state.ownerQuery = query;
      const input = document.getElementById('sp-owner-query');
      if (input) input.value = query;
    }
    const sort = params.get('osort');
    if (sort) state.sort = sort;
    const chip = params.get('ochip');
    if (chip) state.chip = chip;
    if (params.get('oheat') === '1') state.heatAll = true;
    const sources = params.get('osources');
    if (sources === 'all') {
      const all = availableSources.map((src) => String(src.source_key || '')).filter(Boolean);
      if (all.length) state.sourceKeys = all;
    } else if (sources) {
      const keys = sources.split(',').map((key) => key.trim()).filter((key) => titleByKey[key]);
      if (keys.length) state.sourceKeys = keys;
    } else if (publicShare) {
      const urlSource = (params.get('source') || root.getAttribute('data-active-source') || '').trim();
      if (urlSource && titleByKey[urlSource]) state.sourceKeys = [urlSource];
    }
    const compare = params.get('ocompare');
    if (compare) {
      state.compareKeys = compare.split(',').map((key) => key.trim()).filter(Boolean).slice(0, 3);
    }
  };

  const writeUrlState = () => {
    if (!urlSyncEnabled()) return;
    const url = new URL(window.location.href);
    const setOrDel = (key, value, fallback = '') => {
      if (!value || value === fallback) url.searchParams.delete(key);
      else url.searchParams.set(key, value);
    };
    setOrDel('grain', state.grain, 'month');
    setOrDel('oyear', state.year, 'all');
    setOrDel('owner', state.ownerKey);
    setOrDel('oq', state.ownerQuery.trim());
    setOrDel('osort', state.sort, 'projects');
    setOrDel('ochip', state.chip);
    setOrDel('oheat', state.heatAll ? '1' : '');
    const allKeys = availableSources.map((src) => String(src.source_key || '')).filter(Boolean);
    const sameSources =
      state.sourceKeys.length === allKeys.length && allKeys.every((key) => state.sourceKeys.includes(key));
    if (sameSources && allKeys.length > 1) {
      url.searchParams.set('osources', 'all');
    } else if (state.sourceKeys.length > 1) {
      url.searchParams.set('osources', state.sourceKeys.join(','));
    } else {
      url.searchParams.delete('osources');
    }
    if (publicShare && state.sourceKeys.length === 1) {
      url.searchParams.set('source', state.sourceKeys[0]);
    } else if (publicShare) {
      url.searchParams.delete('source');
    }
    setOrDel('ocompare', state.compareKeys.length >= 2 ? state.compareKeys.join(',') : '');
    const next = `${url.pathname}${url.search}${url.hash}`;
    const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
    if (next !== current) {
      history.replaceState(null, '', next);
    }
  };

  applyUrlState();
  const sortSelect = document.getElementById('sp-owner-sort');
  if (sortSelect && state.sort) sortSelect.value = state.sort;

  const syncScopeChips = () => {
    scopesRoot?.querySelectorAll('.sp-owner-scope-check').forEach((input) => {
      const on = state.sourceKeys.includes(input.value);
      input.checked = on;
      input.closest('.sharepoint-scope-chip')?.classList.toggle('is-active', on);
    });
    const total = availableSources.length;
    const selected = state.sourceKeys.length;
    const countEl = document.getElementById('sp-owner-scopes-count');
    const allBtn = document.getElementById('sp-owner-scopes-all');
    const activeBtn = document.getElementById('sp-owner-scopes-active');
    if (countEl) {
      countEl.textContent = total ? `${selected} of ${total}` : '0';
    }
    if (allBtn) {
      const allOn = total > 0 && selected === total;
      allBtn.classList.toggle('is-active', allOn);
      allBtn.disabled = allOn;
      allBtn.textContent = allOn ? 'All selected' : 'Select all';
    }
    if (activeBtn) {
      const oneOn = selected === 1;
      activeBtn.classList.toggle('is-active', oneOn);
      activeBtn.disabled = oneOn;
    }
  };

  const setSources = (keys) => {
    const next = [...new Set(keys.map(String).filter((key) => titleByKey[key]))];
    if (!next.length && availableSources[0]?.source_key) next.push(String(availableSources[0].source_key));
    if (!next.length) return;
    state.sourceKeys = next;
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state.sourceKeys));
    } catch {
      /* ignore */
    }
    syncScopeChips();
    load();
  };

  const hueByOwner = () => {
    const map = {};
    (state.data?.owners || []).forEach((owner) => {
      map[owner.key] = owner.hue;
    });
    return map;
  };

  const queryHaystack = (...parts) =>
    parts
      .map((part) => String(part || '').toLowerCase())
      .filter(Boolean)
      .join(' ');

  const filteredProjects = () => {
    const owners = state.data?.owners || [];
    const query = state.ownerQuery.trim().toLowerCase();
    const rows = [];
    owners.forEach((owner) => {
      const ownerMatches = !query || queryHaystack(owner.name, owner.initials).includes(query);
      (owner.projects || []).forEach((project) => {
        if (state.year !== 'all' && project.year !== state.year) return;
        if (query && !ownerMatches && !queryHaystack(project.project_name).includes(query)) return;
        rows.push({ ...project, owner_key: owner.key, owner_name: owner.name, hue: owner.hue });
      });
    });
    return rows;
  };

  const nowQuarterSuffix = () => `-Q${Math.ceil((new Date().getMonth() + 1) / 3)}`;

  const buildView = () => {
    const projects = filteredProjects();
    const hues = hueByOwner();
    const ownerMap = {};
    const byPeriod = {};
    let unknownDate = 0;
    const nowYear = currentYear;

    projects.forEach((project) => {
      const key = project.owner_key;
      if (!ownerMap[key]) {
        const source = payloadOwner(key) || {};
        ownerMap[key] = {
          key,
          name: project.owner_name,
          aliases: source.aliases || [],
          initials: source.initials || '?',
          hue: hues[key] ?? source.hue ?? 200,
          project_count: 0,
          unknown_date_count: 0,
          first_created: '',
          last_created: '',
          last_activity: '',
          months: {},
          sources: {},
          collaborators: {},
          item_count: 0,
          file_count: 0,
          size_bytes: 0,
          assessment_count: 0,
          created_this_quarter: 0,
          touched_this_quarter: 0,
          this_year: 0,
          last_12_months: Number(source.last_12_months) || 0,
          prev_12_months: Number(source.prev_12_months) || 0,
          streak_months: Number(source.streak_months) || 0,
          share: Number(source.share) || 0,
          dormant: !!source.dormant,
          projects: [],
        };
      }
      const owner = ownerMap[key];
      owner.project_count += 1;
      owner.projects.push(project);
      owner.item_count += Number(project.item_count) || 0;
      owner.file_count += Number(project.file_count) || 0;
      owner.size_bytes += Number(project.size_bytes) || 0;
      if (project.assessment?.id) owner.assessment_count += 1;
      if (project.source_key) {
        owner.sources[project.source_key] = (owner.sources[project.source_key] || 0) + 1;
      }
      (project.collaborators || []).forEach((collab) => {
        const collabKey = collab.key || '';
        if (!collabKey || collabKey === key) return;
        if (!owner.collaborators[collabKey]) {
          owner.collaborators[collabKey] = { key: collabKey, name: collab.name || collabKey, item_count: 0 };
        }
        owner.collaborators[collabKey].item_count += Number(collab.item_count) || 1;
      });
      const activity = project.last_modified || project.date_created || '';
      if (activity && (!owner.last_activity || activity > owner.last_activity)) owner.last_activity = activity;
      if (project.activity_quarter === `${nowYear}${nowQuarterSuffix()}`) {
        owner.touched_this_quarter += 1;
      }
      const period = periodOf(project, state.grain);
      if (!period) {
        unknownDate += 1;
        owner.unknown_date_count += 1;
        return;
      }
      owner.months[period] = (owner.months[period] || 0) + 1;
      if (!byPeriod[period]) byPeriod[period] = { total: 0, owners: {} };
      byPeriod[period].total += 1;
      byPeriod[period].owners[key] = (byPeriod[period].owners[key] || 0) + 1;
      if (!owner.first_created || project.date_created < owner.first_created) owner.first_created = project.date_created;
      if (!owner.last_created || project.date_created > owner.last_created) owner.last_created = project.date_created;
      if (project.year === nowYear) owner.this_year += 1;
      if (project.quarter === `${nowYear}${nowQuarterSuffix()}`) owner.created_this_quarter += 1;
    });

    Object.values(ownerMap).forEach((owner) => {
      if (!owner.last_activity) owner.last_activity = owner.last_created;
      const day = String(owner.last_activity || '').slice(0, 10);
      owner.dormant = !!day && day < dormantCutoff && owner.key !== '_unassigned';
      owner.collaborators = Object.values(owner.collaborators).sort((a, b) => b.item_count - a.item_count).slice(0, 8);
    });

    const sortOwners = (list) => {
      const dirDate = (value, empty) => (value ? value : empty);
      list.sort((a, b) => {
        let cmp = 0;
        switch (state.sort) {
          case 'this_year':
            cmp = b.this_year - a.this_year;
            break;
          case 'last_12':
            cmp = b.last_12_months - a.last_12_months;
            break;
          case 'activity':
            cmp = dirDate(b.last_activity, '').localeCompare(dirDate(a.last_activity, ''));
            break;
          case 'first':
            cmp = dirDate(b.first_created, '').localeCompare(dirDate(a.first_created, ''));
            break;
          case 'streak':
            cmp = b.streak_months - a.streak_months;
            break;
          case 'items':
            cmp = b.item_count - a.item_count;
            break;
          default:
            cmp = b.project_count - a.project_count;
        }
        return cmp || a.name.localeCompare(b.name);
      });
      return list;
    };

    const chipMatch = (owner) => {
      switch (state.chip) {
        case 'this_year':
          return owner.this_year > 0;
        case 'active':
          return owner.touched_this_quarter > 0;
        case 'quiet':
          return owner.dormant;
        case 'unassigned':
          return owner.key === '_unassigned';
        case 'undated':
          return owner.unknown_date_count > 0;
        case 'assessments':
          return owner.assessment_count > 0;
        default:
          return true;
      }
    };

    const owners = sortOwners(Object.values(ownerMap).filter(chipMatch));
    const visibleOwners = owners;
    const visibleKeys = new Set(owners.map((owner) => owner.key));
    const visibleProjects = projects.filter((project) => visibleKeys.has(project.owner_key));
    unknownDate = 0;
    Object.keys(byPeriod).forEach((key) => delete byPeriod[key]);
    owners.forEach((owner) => {
      owner.share = visibleProjects.length > 0 ? owner.project_count / visibleProjects.length : 0;
      owner.projects.forEach((project) => {
        const period = periodOf(project, state.grain);
        if (!period) {
          unknownDate += 1;
          return;
        }
        if (!byPeriod[period]) byPeriod[period] = { total: 0, owners: {} };
        byPeriod[period].total += 1;
        byPeriod[period].owners[owner.key] = (byPeriod[period].owners[owner.key] || 0) + 1;
      });
    });

    const grainKeys =
      state.grain === 'year'
        ? state.data?.timeline?.years || []
        : state.grain === 'quarter'
          ? state.data?.timeline?.quarters || []
          : state.data?.timeline?.months || [];

    const periods = grainKeys.filter((key) => {
      if (state.year === 'all') return true;
      return String(key).startsWith(state.year);
    });
    const visiblePeriods =
      state.grain === 'month' && state.year === 'all' && periods.length > 36
        ? periods.slice(-36)
        : periods;

    const selected = state.ownerKey ? owners.find((owner) => owner.key === state.ownerKey) || null : null;
    const busiestPeriod = visiblePeriods.reduce(
      (best, key) => {
        const total = byPeriod[key]?.total || 0;
        return total > best.count ? { key, count: total } : best;
      },
      { key: '', count: 0 }
    );

    const assigned = owners.filter((o) => o.key !== '_unassigned').reduce((sum, o) => sum + o.project_count, 0);
    const dormantCount = owners.filter((o) => o.dormant).length;
    const top = owners[0];
    const concentration = visibleProjects.length ? (top ? top.project_count / visibleProjects.length : 0) : 0;

    return {
      projects: visibleProjects,
      owners,
      visibleOwners,
      selected,
      periods: visiblePeriods,
      byPeriod,
      unknownDate,
      busiestPeriod,
      total: visibleProjects.length,
      assigned,
      dormantCount,
      concentration,
      clippedMonths: visiblePeriods.length !== periods.length,
    };
  };

  const fillYearOptions = (data) => {
    if (!yearSelect) return;
    const years = data?.timeline?.years || [];
    const current = state.year;
    yearSelect.innerHTML = `<option value="all">All years</option>${years
      .slice()
      .reverse()
      .map((year) => `<option value="${escapeHtml(year)}">${escapeHtml(year)}</option>`)
      .join('')}`;
    yearSelect.value = years.includes(current) ? current : 'all';
    state.year = yearSelect.value;
  };

  const renderKpis = (view) => {
    const top = view.owners[0];
    const kpis = state.data?.kpis || {};
    const yoy = state.year === 'all' ? kpis.yoy_pct : null;
    const cards = [
      {
        key: 'projects',
        label: 'Projects',
        value: view.total,
        hint: state.year === 'all' ? 'in selected folders' : `created in ${state.year}`,
      },
      {
        key: 'owners',
        label: 'Owners',
        value: view.owners.length,
        hint: `${view.assigned} assigned`,
      },
      {
        key: 'top',
        label: 'Top owner',
        value: top ? top.project_count : 0,
        hint: top ? `${top.name} · ${formatPct(top.share)}` : 'No owners yet',
        accent: top ? ownerColor(top.hue) : '',
        owner: top?.key || '',
      },
      {
        key: 'busy',
        label: `Busiest ${state.grain}`,
        value: view.busiestPeriod.count,
        hint: view.busiestPeriod.key ? formatPeriod(view.busiestPeriod.key, state.grain) : '—',
        period: view.busiestPeriod.key || '',
      },
      {
        key: 'year',
        label: state.year === 'all' ? 'This year' : state.year,
        value: state.year === 'all' ? view.owners.reduce((n, o) => n + o.this_year, 0) : view.total,
        hint:
          state.chip || state.ownerKey
            ? 'created this calendar year in this filter'
            : yoy == null
              ? `${kpis.last_12_months || 0} in last 12 months`
              : `${formatSignedPct(yoy)} vs last year`,
      },
      {
        key: 'active',
        label: 'Touched this Q',
        value: view.owners.reduce((n, o) => n + (o.touched_this_quarter ? 1 : 0), 0),
        hint: `${view.owners.reduce((n, o) => n + o.created_this_quarter, 0)} created this quarter`,
        chip: 'active',
      },
      {
        key: 'quiet',
        label: 'Quiet owners',
        value: view.dormantCount,
        hint: 'no folder activity in 12 months',
        chip: 'quiet',
      },
      {
        key: 'items',
        label: 'Items',
        value: view.owners.reduce((n, o) => n + o.item_count, 0),
        hint: 'files and folders in these projects',
      },
    ];
    return `<div class="sp-od-kpis sp-od-kpis-wide">${cards
      .map(
        (card) => `<button type="button" class="sp-od-kpi" data-kpi="${escapeHtml(card.key)}"${
          card.owner ? ` data-owner-key="${escapeHtml(card.owner)}"` : ''
        }${card.chip ? ` data-chip="${escapeHtml(card.chip)}"` : ''}${
          card.period ? ` data-period="${escapeHtml(card.period)}"` : ''
        }${card.accent ? ` style="--od-accent:${card.accent}"` : ''}>
          <span class="sp-od-kpi-label">${escapeHtml(card.label)}</span>
          <strong class="sp-od-kpi-value">${escapeHtml(String(card.value))}</strong>
          <span class="sp-od-kpi-hint">${escapeHtml(card.hint)}</span>
        </button>`
      )
      .join('')}</div>`;
  };

  const renderQualityStrip = (view) => {
    const kpis = state.data?.kpis || {};
    const unassigned = view.owners.find((o) => o.key === '_unassigned')?.project_count || 0;
    const undated = view.unknownDate || 0;
    const concentration = view.concentration || 0;
    const topName = view.owners[0]?.name || kpis.busiest_owner || '';
    const items = [];
    if (unassigned) {
      items.push({
        chip: 'unassigned',
        label: `${unassigned} unassigned folder${unassigned === 1 ? '' : 's'}`,
        tone: 'warn',
      });
    }
    if (undated) {
      items.push({
        chip: 'undated',
        label: `${undated} missing a created date`,
        tone: 'warn',
      });
    }
    if (concentration >= 0.35 && topName) {
      items.push({
        owner: view.owners[0]?.key || kpis.busiest_owner_key || '',
        label: `${topName} owns ${formatPct(concentration)} of folders`,
        tone: 'alert',
      });
    }
    if (!items.length) return '';
    return `<div class="sp-od-quality" role="status">${items
      .map(
        (item) =>
          `<button type="button" class="sp-od-quality-chip is-${item.tone}"${
            item.chip ? ` data-chip="${escapeHtml(item.chip)}"` : ''
          }${item.owner ? ` data-owner-key="${escapeHtml(item.owner)}"` : ''}>${escapeHtml(item.label)}</button>`
      )
      .join('')}</div>`;
  };

  const catalogMixHtml = (owner) => {
    const entries = Object.entries(owner.sources || {});
    if (entries.length < 2) {
      const only = entries[0];
      return only ? `<span class="sp-od-catalog-one">${escapeHtml(titleByKey[only[0]] || only[0])}</span>` : '';
    }
    const total = owner.project_count || 1;
    return `<span class="sp-od-mix" title="Catalog mix">${entries
      .map(([key, count]) => {
        const pct = Math.max(10, (count / total) * 100);
        const label = titleByKey[key] || key;
        const hue = Math.abs(hashHue(key));
        return `<i style="width:${pct}%;background:hsla(${hue},55%,45%,0.9)" title="${escapeHtml(label)}: ${count}"></i>`;
      })
      .join('')}</span>`;
  };

  const hashHue = (value) => {
    let hash = 0;
    const text = String(value || '');
    for (let i = 0; i < text.length; i += 1) hash = (hash * 31 + text.charCodeAt(i)) >>> 0;
    return hash % 360;
  };

  const assessmentBadge = (project) => {
    const id = Number(project?.assessment?.id) || 0;
    if (!id) return '';
    if (publicShare) {
      return `<span class="sp-od-assess-pill" title="An assessment workbook is on file. Sign in to open it.">📋 Assessment</span>`;
    }
    return `<a class="sp-od-assess-link" href="index.php?id=${id}&amp;view=1">📋 Assessment</a>`;
  };

  const renderPortraitRow = (view) => {
    const top = view.visibleOwners.slice(0, 8);
    if (!top.length) return '';
    const max = top[0].project_count || 1;
    return `<div class="sp-od-portraits" role="list">${top
      .map((owner) => {
        const share = Math.max(0.08, owner.project_count / max);
        const active = owner.key === state.ownerKey ? ' is-active' : '';
        return `<button type="button" class="sp-od-portrait${active}" role="listitem" data-owner-key="${escapeHtml(owner.key)}" title="${escapeHtml(owner.name)}">
          <span class="sp-od-avatar" style="--hue:${owner.hue}; --share:${share}">
            <span>${escapeHtml(owner.initials)}</span>
          </span>
          <span class="sp-od-portrait-name">${highlightQuery(owner.name)}</span>
          <span class="sp-od-portrait-count">${owner.project_count} project${owner.project_count === 1 ? '' : 's'}</span>
        </button>`;
      })
      .join('')}</div>`;
  };

  const seriesOwners = (view) => {
    const ranked = view.owners.filter((o) => o.key !== '_unassigned' || view.owners.length === 1);
    const top = ranked.slice(0, TOP_SERIES);
    const keys = new Set(top.map((o) => o.key));
    const hasOthers = view.owners.some((o) => !keys.has(o.key) && o.project_count > 0);
    return { top, keys, hasOthers };
  };

  const yoyChartNote = (view) => {
    const kpis = state.data?.kpis || {};
    if (state.year !== 'all') {
      const prevYear = String(Number(state.year) - 1);
      const prev = Number(state.data?.timeline?.by_year?.[prevYear]?.total) || 0;
      if (!prev && !view.total) return '';
      const pct = prev ? (view.total - prev) / prev : 1;
      return ` ${state.year} vs ${prevYear}: ${formatSignedPct(pct)} (${view.total} vs ${prev}).`;
    }
    if (view.busiestPeriod.key && state.grain === 'month') {
      const [year, month] = String(view.busiestPeriod.key).split('-');
      const prevKey = `${Number(year) - 1}-${month}`;
      const prev = Number(state.data?.timeline?.by_month?.[prevKey]?.total) || 0;
      if (prev) {
        const pct = (view.busiestPeriod.count - prev) / prev;
        return ` Busiest month ${formatSignedPct(pct)} vs ${formatPeriod(prevKey, 'month')}.`;
      }
    }
    if (kpis.prev_year_count || kpis.this_year_count) {
      return ` This year ${formatSignedPct(kpis.yoy_pct || 0)} vs last year (${kpis.this_year_count || 0} vs ${kpis.prev_year_count || 0}). Last 12 months ${formatSignedPct(kpis.last_12_pct || 0)}.`;
    }
    return '';
  };

  const renderChart = (view) => {
    if (!view.periods.length) {
      return `<div class="sp-od-chart-empty">No creation dates in this range yet. Sync folders so Created dates are stored.</div>`;
    }
    const { top, keys, hasOthers } = seriesOwners(view);
    const maxTotal = Math.max(1, ...view.periods.map((key) => view.byPeriod[key]?.total || 0));
    const barW = view.periods.length > 24 ? 22 : view.periods.length > 12 ? 28 : 36;
    const gap = 8;
    const chartH = 180;
    const labelH = 36;
    const padL = 40;
    const padR = 12;
    const width = padL + padR + view.periods.length * (barW + gap);
    const height = chartH + labelH + 8;
    const selected = view.selected?.key || '';
    const focusMax = selected
      ? Math.max(1, ...view.periods.map((key) => view.byPeriod[key]?.owners?.[selected] || 0))
      : maxTotal;
    const scaleMax = selected ? Math.max(focusMax, 1) : maxTotal;

    const bars = view.periods
      .map((period, index) => {
        const bucket = view.byPeriod[period] || { total: 0, owners: {} };
        const x = padL + index * (barW + gap);
        const segments = [];
        const hueFor = (ownerKey) => (view.owners.find((owner) => owner.key === ownerKey) || {}).hue || 200;
        if (selected) {
          const focusCount = bucket.owners[selected] || 0;
          const focusH = (focusCount / scaleMax) * chartH;
          if (focusCount) {
            const owner = view.owners.find((row) => row.key === selected);
            const focusProjects = projectsForOwnerPeriod(view, selected, period);
            const tip = tooltipForBucket(owner?.name || '', period, focusProjects);
            segments.push(
              `<rect class="sp-od-bar-seg" x="${x}" y="${(chartH - Math.max(3, focusH)).toFixed(1)}" width="${barW}" height="${Math.max(3, focusH).toFixed(1)}" rx="3"
                fill="${ownerColor(owner?.hue ?? hueFor(selected), 0.95)}" data-period="${escapeHtml(period)}" data-owner="${escapeHtml(selected)}"
                data-count="${focusCount}" data-label="${escapeHtml(owner?.name || '')}"><title>${escapeHtml(tip)}</title></rect>`
            );
          }
        } else {
          let y = chartH;
          const pushSeg = (ownerKey, count, hue, label, projects) => {
            if (!count) return;
            const h = Math.max(3, (count / scaleMax) * chartH);
            y -= h;
            const tip = tooltipForBucket(label, period, projects);
            segments.push(
              `<rect class="sp-od-bar-seg" x="${x}" y="${y.toFixed(1)}" width="${barW}" height="${h.toFixed(1)}" rx="3"
                fill="${ownerColor(hue, 0.92)}" data-period="${escapeHtml(period)}" data-owner="${escapeHtml(ownerKey)}"
                data-count="${count}" data-label="${escapeHtml(label)}"><title>${escapeHtml(tip)}</title></rect>`
            );
          };
          top.forEach((owner) =>
            pushSeg(
              owner.key,
              bucket.owners[owner.key] || 0,
              owner.hue,
              owner.name,
              projectsForOwnerPeriod(view, owner.key, period)
            )
          );
          if (hasOthers) {
            const otherProjects = projectsForOthersPeriod(view, period, keys);
            pushSeg('_others', otherProjects.length, 220, 'Others', otherProjects);
          }
        }
        const label = formatPeriod(period, state.grain);
        const short =
          state.grain === 'month'
            ? label.replace(/ (\d{4})$/, (_, year) => (index === 0 || period.endsWith('-01') ? ` '${year.slice(2)}` : ''))
            : label;
        return `${segments.join('')}
          <text class="sp-od-axis" x="${x + barW / 2}" y="${chartH + 16}" text-anchor="middle">${escapeHtml(short)}</text>`;
      })
      .join('');

    const ticks = [scaleMax, Math.round(scaleMax / 2), 0]
      .filter((value, index, all) => all.indexOf(value) === index)
      .map(
        (value) =>
          `<text class="sp-od-axis sp-od-axis-y" x="${padL - 6}" y="${(chartH - (value / scaleMax) * chartH + 4).toFixed(1)}" text-anchor="end">${value}</text>`
      )
      .join('');

    const legend = [
      ...top.map(
        (owner) =>
          `<button type="button" class="sp-od-legend-item${owner.key === selected ? ' is-active' : ''}" data-owner-key="${escapeHtml(owner.key)}">
            <i style="background:${ownerColor(owner.hue)}"></i>${escapeHtml(owner.name)}
          </button>`
      ),
      hasOthers ? `<span class="sp-od-legend-item is-static"><i style="background:${ownerColor(220)}"></i>Others</span>` : '',
    ].join('');

    return `<div class="sp-od-chart-wrap">
      <div class="sp-od-chart-head">
        <h3>Projects created over time</h3>
        <p>${selected ? 'Showing this owner’s folders by period. Click a bar segment to list those projects, or click the person again to return to the stacked view.' : 'Stacked by owner. Click a person to focus them, or click a bar segment to list those project folders.'}${yoyChartNote(view)}</p>
      </div>
      <div class="sp-od-chart-scroll">
        <svg class="sp-od-chart" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" role="img" aria-label="Projects created by ${state.grain}">
          ${ticks}
          ${bars}
        </svg>
      </div>
      <div class="sp-od-legend">${legend}</div>
    </div>`;
  };

  const renderLeaderboard = (view) => {
    const max = Math.max(
      1,
      ...view.visibleOwners.map((owner) => {
        if (state.sort === 'items') return owner.item_count || 0;
        if (state.sort === 'this_year') return owner.this_year || 0;
        if (state.sort === 'last_12') return owner.last_12_months || 0;
        if (state.sort === 'streak') return owner.streak_months || 0;
        return owner.project_count || 0;
      })
    );
    if (!view.visibleOwners.length) {
      return `<div class="sp-od-empty">No people or projects match this filter.</div>`;
    }
    return `<div class="sp-od-board">
      <div class="sp-od-board-head">
        <h3>Owner leaderboard</h3>
        <p>Tick 2–3 people to compare. Bars follow the current sort.</p>
      </div>
      <ol class="sp-od-ranks">
        ${view.visibleOwners
          .map((owner, index) => {
            const barValue =
              state.sort === 'items'
                ? owner.item_count
                : state.sort === 'this_year'
                  ? owner.this_year
                  : state.sort === 'last_12'
                    ? owner.last_12_months
                    : state.sort === 'streak'
                      ? owner.streak_months
                      : owner.project_count;
            const pct = Math.max(4, (barValue / max) * 100);
            const active = owner.key === state.ownerKey ? ' is-active' : '';
            const checked = state.compareKeys.includes(owner.key) ? ' checked' : '';
            const meta = [
              `${owner.project_count} folder${owner.project_count === 1 ? '' : 's'}`,
              `${formatPct(owner.share)}`,
              owner.this_year ? `${owner.this_year} this year` : '',
              owner.streak_months > 1 ? `${owner.streak_months}-mo streak` : '',
              owner.last_activity ? `active ${formatDay(owner.last_activity)}` : owner.last_created ? `last ${formatDay(owner.last_created)}` : '',
              owner.dormant ? 'quiet' : '',
            ]
              .filter(Boolean)
              .join(' · ');
            return `<li>
              <div class="sp-od-rank-row">
                <label class="sp-od-compare-pick-wrap" title="Select to compare">
                  <input type="checkbox" class="sp-od-compare-pick" data-owner-key="${escapeHtml(owner.key)}"${checked}>
                </label>
                <button type="button" class="sp-od-rank${active}" data-owner-key="${escapeHtml(owner.key)}">
                  <span class="sp-od-rank-n">${index + 1}</span>
                  <span class="sp-od-avatar sp-od-avatar-sm" style="--hue:${owner.hue}; --share:1"><span>${escapeHtml(owner.initials)}</span></span>
                  <span class="sp-od-rank-copy">
                    <strong>${highlightQuery(owner.name)}</strong>
                    <span>${escapeHtml(meta)}</span>
                    ${catalogMixHtml(owner)}
                  </span>
                  <span class="sp-od-rank-bar"><i style="width:${pct}%; background:${ownerColor(owner.hue)}"></i></span>
                  <b>${state.sort === 'items' ? owner.item_count : owner.project_count}</b>
                </button>
              </div>
            </li>`;
          })
          .join('')}
      </ol>
    </div>`;
  };

  const renderHeatmap = (view) => {
    const rows = state.heatAll ? view.visibleOwners : view.visibleOwners.slice(0, HEATMAP_OWNERS);
    if (!rows.length || !view.periods.length) return '';
    const maxCell = Math.max(
      1,
      ...rows.flatMap((owner) => view.periods.map((period) => view.byPeriod[period]?.owners?.[owner.key] || 0))
    );
    const yearOf = (key) => {
      const match = /^(\d{4})/.exec(String(key || ''));
      return match ? match[1] : '';
    };
    const colLabel = (key) => {
      if (state.grain === 'month') {
        const match = /^(\d{4})-(\d{2})$/.exec(key);
        return match ? MONTH_LABELS[Number(match[2]) - 1] || match[2] : key;
      }
      if (state.grain === 'quarter') return key.replace(/^\d{4}-/, '');
      return key;
    };
    const yearGroups = [];
    view.periods.forEach((key) => {
      const year = yearOf(key) || '—';
      const last = yearGroups[yearGroups.length - 1];
      if (last && last.year === year) last.span += 1;
      else yearGroups.push({ year, span: 1 });
    });
    const showYearRow = state.grain !== 'year' && yearGroups.some((group) => group.year !== '—');
    const yearRow = showYearRow
      ? `<tr class="sp-od-heat-years">
          <th scope="col" rowspan="2" class="sp-od-heat-owner-head">Owner</th>
          ${yearGroups
            .map(
              (group) =>
                `<th colspan="${group.span}" class="sp-od-heat-year" title="${escapeHtml(group.year)}">${escapeHtml(group.year)}</th>`
            )
            .join('')}
        </tr>`
      : '';
    const periodHead = `<tr class="sp-od-heat-periods">
      ${showYearRow ? '' : '<th scope="col" class="sp-od-heat-owner-head">Owner</th>'}
      ${view.periods
        .map(
          (key) =>
            `<th scope="col" class="sp-od-heat-period" title="${escapeHtml(formatPeriod(key, state.grain))}">${escapeHtml(colLabel(key))}</th>`
        )
        .join('')}
    </tr>`;
    const wide = view.periods.length > 14;

    return `<div class="sp-od-heat">
      <div class="sp-od-chart-head">
        <h3>Owner × ${state.grain} heatmap</h3>
        <p>Darker cells mean more project folders created in that period. Click a cell to list those project folders.${wide ? ' Scroll sideways to see every year.' : ''} ${
          view.visibleOwners.length > HEATMAP_OWNERS
            ? state.heatAll
              ? 'Showing every owner in this filter.'
              : `Showing the top ${HEATMAP_OWNERS} of ${view.visibleOwners.length}.`
            : ''
        }</p>
        ${
          view.visibleOwners.length > HEATMAP_OWNERS
            ? `<button type="button" class="button ghost sp-od-heat-all" id="sp-od-heat-all">${state.heatAll ? 'Show top 12' : 'Show all owners'}</button>`
            : ''
        }
      </div>
      <div class="sp-od-heat-scroll${wide ? ' is-wide' : ''}" tabindex="0" role="region" aria-label="Owner heatmap by ${state.grain}">
        <table class="sp-od-heat-table">
          <thead>
            ${yearRow}
            ${periodHead}
          </thead>
          <tbody>
            ${rows
              .map((owner) => {
                const active = owner.key === state.ownerKey ? ' is-active' : '';
                return `<tr class="${active}">
                  <th scope="row">
                    <button type="button" class="sp-od-heat-owner" data-owner-key="${escapeHtml(owner.key)}">
                      <span class="sp-od-swatch" style="background:${ownerColor(owner.hue)}"></span>
                      ${highlightQuery(owner.name)}
                    </button>
                  </th>
                  ${view.periods
                    .map((period) => {
                      const count = view.byPeriod[period]?.owners?.[owner.key] || 0;
                      const t = count / maxCell;
                      const bg = count ? ownerColor(owner.hue, 0.12 + t * 0.78) : 'transparent';
                      if (!count) {
                        return `<td><span class="sp-od-heat-cell is-empty" style="background:transparent" aria-hidden="true"></span></td>`;
                      }
                      const cellProjects = projectsForOwnerPeriod(view, owner.key, period);
                      const tip = tooltipForBucket(owner.name, period, cellProjects);
                      return `<td><button type="button" class="sp-od-heat-cell" style="background:${bg}" title="${escapeAttr(tip)}" data-owner-key="${escapeHtml(owner.key)}" data-period="${escapeHtml(period)}" data-label="${escapeHtml(owner.name)}" aria-label="${escapeAttr(tip.replace(/\n/g, '. '))}">${count}</button></td>`;
                    })
                    .join('')}
                </tr>`;
              })
              .join('')}
          </tbody>
        </table>
      </div>
    </div>`;
  };

  const groupProjects = (projects) => {
    const years = {};
    projects.forEach((project) => {
      const year = project.year || 'Unknown';
      const month = project.month || 'unknown';
      if (!years[year]) years[year] = {};
      if (!years[year][month]) years[year][month] = [];
      years[year][month].push(project);
    });
    return Object.keys(years)
      .sort((a, b) => b.localeCompare(a))
      .map((year) => ({
        year,
        months: Object.keys(years[year])
          .sort((a, b) => b.localeCompare(a))
          .map((month) => ({ month, projects: years[year][month] })),
      }));
  };

  const sparkline = (owner, periods) => {
    const counts = periods.map((key) => owner.months[key] || 0);
    const max = Math.max(1, ...counts);
    const w = Math.max(80, periods.length * 6);
    const h = 36;
    const points = counts.map((count, index) => {
      const x = periods.length <= 1 ? w / 2 : (index / (periods.length - 1)) * (w - 4) + 2;
      const y = h - 4 - (count / max) * (h - 8);
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    });
    return `<svg class="sp-od-spark" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}" aria-hidden="true">
      <polyline fill="none" stroke="${ownerColor(owner.hue)}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="${points.join(' ')}"></polyline>
    </svg>`;
  };

  const renderDetail = (view) => {
    const owner = view.selected;
    if (!owner) {
      return `<aside class="sp-od-detail is-empty">
        <h3>Select a person</h3>
        <p>Click an owner to see the project folders they created, grouped by year and month.</p>
      </aside>`;
    }
    const groups = groupProjects(owner.projects);
    const sourceBits = [...new Set(owner.projects.map((p) => p.source_title).filter(Boolean))];
    return `<aside class="sp-od-detail">
      <div class="sp-od-detail-head">
        <span class="sp-od-avatar" style="--hue:${owner.hue}; --share:1"><span>${escapeHtml(owner.initials)}</span></span>
        <div>
          <h3>${highlightQuery(owner.name)}</h3>
          <p>${owner.project_count} project folder${owner.project_count === 1 ? '' : 's'} · ${formatPct(owner.share)} · ${escapeHtml(sourceBits.join(', ') || 'Selected catalogs')}</p>
          ${
            owner.aliases?.length
              ? `<p class="sp-od-alias">Also seen as ${escapeHtml(owner.aliases.join(', '))}</p>`
              : ''
          }
        </div>
        <button type="button" class="button ghost" id="sp-owner-clear" title="Clear owner filter">Clear</button>
      </div>
      <div class="sp-od-detail-meta">
        <span><b>${owner.first_created ? formatDay(owner.first_created) : '—'}</b> first</span>
        <span><b>${owner.last_created ? formatDay(owner.last_created) : '—'}</b> latest created</span>
        <span><b>${owner.last_activity ? formatDay(owner.last_activity) : '—'}</b> last activity</span>
        <span><b>${owner.this_year}</b> this year</span>
        <span><b>${owner.last_12_months}</b> last 12 mo</span>
        <span><b>${owner.streak_months}</b> mo streak</span>
        <span><b>${owner.item_count}</b> items${owner.size_bytes ? ` · ${formatBytes(owner.size_bytes)}` : ''}</span>
        <span><b>${owner.unknown_date_count}</b> undated</span>
        ${owner.dormant ? '<span class="sp-od-quiet-pill">Quiet 12+ months</span>' : ''}
      </div>
      ${catalogMixHtml(owner)}
      ${
        owner.collaborators?.length
          ? `<div class="sp-od-collabs"><span>Collaborators</span>${owner.collaborators
              .map(
                (collab) =>
                  `<button type="button" class="sp-od-collab" data-owner-key="${escapeHtml(collab.key)}">${escapeHtml(collab.name)} <small>${collab.item_count}</small></button>`
              )
              .join('')}</div>`
          : ''
      }
      ${sparkline(owner, view.periods)}
      <div class="sp-od-timeline">
        ${groups
          .map(
            (year) => `<section class="sp-od-year">
              <h4>${escapeHtml(year.year)} <small>${year.months.reduce((n, m) => n + m.projects.length, 0)}</small></h4>
              ${year.months
                .map(
                  (month) => `<div class="sp-od-month">
                    <span class="sp-od-month-label">${month.month === 'unknown' ? 'Unknown' : escapeHtml(formatMonth(month.month))}</span>
                    <ul>
                      ${month.projects
                        .map(
                          (project) => `<li>
                            <button type="button" class="sp-od-project" data-project-name="${escapeHtml(project.project_name)}" data-source-key="${escapeHtml(project.source_key)}" data-folder-url="${escapeHtml(project.folder_url || '')}">
                              <strong>${highlightQuery(project.project_name)}</strong>
                              <span>${escapeHtml(project.source_title || '')}${project.date_created ? ` · ${escapeHtml(formatDay(project.date_created))}` : ''}${project.item_count ? ` · ${project.item_count} items` : ''}</span>
                            </button>
                            ${assessmentBadge(project)}
                            ${
                              project.folder_url
                                ? `<a class="sp-od-open-sp" href="${escapeHtml(project.folder_url)}" target="_blank" rel="noopener noreferrer" title="Open in SharePoint">🔗</a>`
                                : ''
                            }
                          </li>`
                        )
                        .join('')}
                    </ul>
                  </div>`
                )
                .join('')}
            </section>`
          )
          .join('')}
      </div>
    </aside>`;
  };

  const render = () => {
    if (state.loading) {
      body.innerHTML = `<p class="panel-help">Loading owner insights…</p>`;
      return;
    }
    if (state.error) {
      body.innerHTML = `<div class="alert alert-error">⚠️ ${escapeHtml(state.error)}</div>`;
      return;
    }
    if (!state.data) return;
    const view = buildView();
    if (!view.total && !(state.data.kpis?.project_count > 0) && state.year === 'all') {
      body.innerHTML = `<div class="sp-od-empty-hero">
        <strong>No project folders yet</strong>
        <p>Sync one or more SharePoint catalogs, then this dashboard will show who created each project folder and when.</p>
      </div>`;
      return;
    }
    const folderNote =
      state.sourceKeys.length === 1
        ? titleByKey[state.sourceKeys[0]] || 'this folder'
        : `${state.sourceKeys.length} folders`;
    body.innerHTML = `
      <p class="sp-od-context">${view.total} project${view.total === 1 ? '' : 's'} across ${escapeHtml(folderNote)}${
        view.unknownDate ? ` · ${view.unknownDate} without a created date` : ''
      }${view.clippedMonths ? ' · chart shows the last 36 months' : ''}${
        state.ownerQuery.trim() ? ` · matching “${escapeHtml(state.ownerQuery.trim())}”` : ''
      }${
        state.ownerKey && view.selected ? ` · focused on ${escapeHtml(view.selected.name)}` : ''
      }</p>
      ${renderKpis(view)}
      ${renderQualityStrip(view)}
      ${renderPortraitRow(view)}
      <div class="sp-od-grid">
        ${renderLeaderboard(view)}
        ${renderDetail(view)}
      </div>
      ${renderChart(view)}
      ${renderHeatmap(view)}
    `;
    bindBody();
    writeUrlState();
    syncCompareButton();
  };

  const toggleOwner = (key) => {
    state.ownerKey = state.ownerKey === key ? '' : key;
    render();
  };

  const setChip = (chip) => {
    state.chip = state.chip === chip ? '' : chip || '';
    syncChips();
    render();
  };

  const syncChips = () => {
    document.querySelectorAll('#sp-od-chips [data-chip]').forEach((btn) => {
      const on = (btn.getAttribute('data-chip') || '') === state.chip;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  };

  const syncCompareButton = () => {
    const btn = document.getElementById('sp-owner-compare');
    if (!btn) return;
    const n = state.compareKeys.length;
    btn.disabled = n < 2 || n > 3;
    btn.textContent = n ? `⚖️ Compare (${n})` : '⚖️ Compare';
  };

  const csvEscape = (value) => {
    const text = String(value ?? '');
    if (/[",\n]/.test(text)) return `"${text.replace(/"/g, '""')}"`;
    return text;
  };

  const exportCsv = () => {
    if (!state.data) return;
    const view = buildView();
    const headers = [
      'Owner',
      'Projects',
      'Share',
      'This year',
      'Last 12 months',
      'Streak months',
      'First created',
      'Last created',
      'Last activity',
      'Dormant',
      'Items',
      'Files',
      'Size bytes',
      'Catalogs',
      'Collaborators',
      'Assessments',
    ];
    const rows = view.visibleOwners.map((owner) => [
      owner.name,
      owner.project_count,
      formatPct(owner.share),
      owner.this_year,
      owner.last_12_months,
      owner.streak_months,
      owner.first_created,
      owner.last_created,
      owner.last_activity,
      owner.dormant ? 'yes' : 'no',
      owner.item_count,
      owner.file_count,
      owner.size_bytes,
      Object.keys(owner.sources || {})
        .map((key) => `${titleByKey[key] || key}:${owner.sources[key]}`)
        .join('; '),
      (owner.collaborators || []).map((collab) => collab.name).join('; '),
      owner.assessment_count,
    ]);
    const csv = [headers, ...rows].map((row) => row.map(csvEscape).join(',')).join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `project-owners-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  };

  const printSnapshot = () => {
    document.documentElement.classList.add('sp-od-printing');
    const done = () => document.documentElement.classList.remove('sp-od-printing');
    window.addEventListener('afterprint', done, { once: true });
    window.print();
    setTimeout(done, 1000);
  };

  const compareDialog = document.getElementById('sp-od-compare-dialog');
  const compareBody = document.getElementById('sp-od-compare-body');
  const compareSub = document.getElementById('sp-od-compare-sub');

  const closeCompareDialog = () => {
    if (compareDialog?.open) compareDialog.close();
  };

  const openCompareDialog = () => {
    if (!compareDialog || !compareBody || state.compareKeys.length < 2) return;
    const view = buildView();
    const picked = state.compareKeys
      .map((key) => view.owners.find((owner) => owner.key === key) || payloadOwner(key))
      .filter(Boolean);
    if (picked.length < 2) return;
    if (compareSub) compareSub.textContent = picked.map((owner) => owner.name).join(' · ');
    const rows = [
      ['Folders', (o) => o.project_count],
      ['Share', (o) => formatPct(o.share)],
      ['This year', (o) => o.this_year],
      ['Last 12 months', (o) => o.last_12_months],
      ['Streak', (o) => `${o.streak_months || 0} mo`],
      ['First created', (o) => (o.first_created ? formatDay(o.first_created) : '—')],
      ['Latest created', (o) => (o.last_created ? formatDay(o.last_created) : '—')],
      ['Last activity', (o) => (o.last_activity ? formatDay(o.last_activity) : '—')],
      ['Quiet', (o) => (o.dormant ? 'Yes' : 'No')],
      ['Touched this quarter', (o) => o.touched_this_quarter],
      ['Created this quarter', (o) => o.created_this_quarter],
      ['Items', (o) => o.item_count],
      ['Files', (o) => o.file_count],
      ['Size', (o) => formatBytes(o.size_bytes)],
      ['Assessments', (o) => o.assessment_count],
      [
        'Catalogs',
        (o) =>
          Object.entries(o.sources || {})
            .map(([key, count]) => `${titleByKey[key] || key} (${count})`)
            .join(', ') || '—',
      ],
      [
        'Collaborators',
        (o) => (o.collaborators || []).map((collab) => collab.name).join(', ') || '—',
      ],
    ];
    compareBody.innerHTML = `<table class="sp-od-compare-table">
      <thead>
        <tr>
          <th scope="col">Metric</th>
          ${picked
            .map(
              (owner) =>
                `<th scope="col"><span class="sp-od-avatar sp-od-avatar-sm" style="--hue:${owner.hue || 200}; --share:1"><span>${escapeHtml(owner.initials || '?')}</span></span> ${escapeHtml(owner.name)}</th>`
            )
            .join('')}
        </tr>
      </thead>
      <tbody>
        ${rows
          .map(
            ([label, getter]) => `<tr>
              <th scope="row">${escapeHtml(label)}</th>
              ${picked.map((owner) => `<td>${escapeHtml(String(getter(owner) ?? '—'))}</td>`).join('')}
            </tr>`
          )
          .join('')}
      </tbody>
    </table>`;
    if (!compareDialog.open) compareDialog.showModal();
  };

  const bindBody = () => {
    body.querySelectorAll('[data-owner-key]').forEach((el) => {
      if (el.tagName === 'INPUT' || el.classList.contains('sp-od-compare-pick')) return;
      if (el.classList.contains('sp-od-kpi') || el.classList.contains('sp-od-quality-chip')) return;
      if (el.tagName === 'BUTTON' && el.classList.contains('sp-od-heat-cell')) {
        el.addEventListener('click', () => {
          const ownerKey = el.getAttribute('data-owner-key') || '';
          const period = el.getAttribute('data-period') || '';
          const label = el.getAttribute('data-label') || '';
          if (!ownerKey || !period) return;
          const view = buildView();
          const projects = projectsForOwnerPeriod(view, ownerKey, period);
          openCellDialog({ label, period, projects, showOwner: false });
        });
        return;
      }
      if (el.tagName === 'RECT') return;
      el.addEventListener('click', (event) => {
        event.preventDefault();
        toggleOwner(el.getAttribute('data-owner-key') || '');
      });
    });
    body.querySelectorAll('.sp-od-bar-seg').forEach((el) => {
      el.addEventListener('click', () => {
        const key = el.getAttribute('data-owner') || '';
        const period = el.getAttribute('data-period') || '';
        const label = el.getAttribute('data-label') || '';
        if (!period) return;
        const view = buildView();
        if (key === '_others') {
          const { keys } = seriesOwners(view);
          const projects = projectsForOthersPeriod(view, period, keys);
          openCellDialog({ label: 'Others', period, projects, showOwner: true });
          return;
        }
        if (!key) return;
        const projects = projectsForOwnerPeriod(view, key, period);
        openCellDialog({ label: label || key, period, projects, showOwner: false });
      });
    });
    document.getElementById('sp-owner-clear')?.addEventListener('click', () => {
      state.ownerKey = '';
      render();
    });
    body.querySelectorAll('.sp-od-project').forEach((btn) => {
      btn.addEventListener('click', () => openOwnerProject(btn));
    });
    body.querySelectorAll('.sp-od-compare-pick').forEach((input) => {
      input.addEventListener('click', (event) => event.stopPropagation());
      input.addEventListener('change', (event) => {
        event.stopPropagation();
        const key = input.getAttribute('data-owner-key') || '';
        if (!key) return;
        if (input.checked) {
          if (!state.compareKeys.includes(key) && state.compareKeys.length < 3) {
            state.compareKeys = [...state.compareKeys, key];
          } else if (state.compareKeys.length >= 3) {
            input.checked = false;
          }
        } else {
          state.compareKeys = state.compareKeys.filter((item) => item !== key);
        }
        syncCompareButton();
        writeUrlState();
      });
    });
    body.querySelectorAll('.sp-od-kpi, .sp-od-quality-chip').forEach((el) => {
      el.addEventListener('click', () => {
        const owner = el.getAttribute('data-owner-key') || '';
        const chip = el.getAttribute('data-chip');
        const period = el.getAttribute('data-period') || '';
        const kpi = el.getAttribute('data-kpi') || '';
        if (period) {
          const view = buildView();
          const projects = view.projects.filter((project) => periodOf(project, state.grain) === period);
          openCellDialog({ label: 'All owners', period, projects, showOwner: true });
          return;
        }
        if (chip != null && chip !== '') {
          setChip(chip);
          return;
        }
        if (owner) {
          toggleOwner(owner);
          return;
        }
        if (kpi === 'projects' || kpi === 'owners') {
          state.ownerKey = '';
          state.chip = '';
          syncChips();
          render();
          return;
        }
        if (kpi === 'year' && state.year === 'all' && yearSelect) {
          yearSelect.value = currentYear;
          state.year = currentYear;
          render();
          return;
        }
        if (kpi === 'items') {
          state.sort = 'items';
          if (sortSelect) sortSelect.value = 'items';
          render();
        }
      });
    });
    document.getElementById('sp-od-heat-all')?.addEventListener('click', () => {
      state.heatAll = !state.heatAll;
      render();
    });
  };

  const load = () => {
    const fallbackKeys = publicShare
      ? [(root.getAttribute('data-active-source') || '').trim()].filter((key) => titleByKey[key])
      : availableSources.map((s) => String(s.source_key || '')).filter(Boolean);
    const keys = state.sourceKeys.length ? state.sourceKeys : fallbackKeys;
    state.loading = true;
    state.error = '';
    render();
    const extra = {
      source: keys[0] || '',
      sources: keys.join(','),
    };
    fetch(ownerApiUrl('owner_stats', extra), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then((response) => response.json())
      .then((payload) => {
        if (!payload?.ok) throw new Error(payload?.error || 'Unable to load owner stats.');
        state.data = payload;
        state.loading = false;
        if (state.ownerKey && !(payload.owners || []).some((o) => o.key === state.ownerKey)) {
          state.ownerKey = '';
        }
        fillYearOptions(payload);
        render();
      })
      .catch((error) => {
        state.loading = false;
        state.error = error.message || 'Owner stats failed.';
        render();
      });
  };

  root.querySelectorAll('[data-grain]').forEach((btn) => {
    btn.addEventListener('click', () => {
      state.grain = btn.getAttribute('data-grain') === 'quarter' ? 'quarter' : btn.getAttribute('data-grain') === 'year' ? 'year' : 'month';
      root.querySelectorAll('[data-grain]').forEach((other) => {
        const on = other === btn;
        other.classList.toggle('is-active', on);
        other.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      render();
    });
  });

  yearSelect?.addEventListener('change', () => {
    state.year = yearSelect.value || 'all';
    render();
  });

  document.getElementById('sp-owner-query')?.addEventListener('input', (event) => {
    state.ownerQuery = event.target.value || '';
    render();
  });

  sortSelect?.addEventListener('change', () => {
    state.sort = sortSelect.value || 'projects';
    render();
  });

  document.querySelectorAll('#sp-od-chips [data-chip]').forEach((btn) => {
    btn.addEventListener('click', () => setChip(btn.getAttribute('data-chip') || ''));
  });
  syncChips();

  document.getElementById('sp-owner-export')?.addEventListener('click', () => exportCsv());
  document.getElementById('sp-owner-print')?.addEventListener('click', () => printSnapshot());
  document.getElementById('sp-owner-compare')?.addEventListener('click', () => openCompareDialog());
  document.getElementById('sp-od-compare-close')?.addEventListener('click', () => closeCompareDialog());
  compareDialog?.addEventListener('click', (event) => {
    if (event.target === compareDialog) closeCompareDialog();
  });

  root.querySelectorAll('[data-grain]').forEach((btn) => {
    const on = (btn.getAttribute('data-grain') || 'month') === state.grain;
    btn.classList.toggle('is-active', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  });

  scopesRoot?.querySelectorAll('.sp-owner-scope-check').forEach((input) => {
    input.addEventListener('change', () => {
      const checked = Array.from(scopesRoot.querySelectorAll('.sp-owner-scope-check:checked')).map((el) => el.value);
      if (!checked.length) {
        input.checked = true;
        return;
      }
      setSources(checked);
    });
  });

  document.getElementById('sp-owner-scopes-all')?.addEventListener('click', () => {
    setSources(availableSources.map((src) => String(src.source_key || '')).filter(Boolean));
  });

  document.getElementById('sp-owner-scopes-active')?.addEventListener('click', () => {
    const activeKey = (root.getAttribute('data-active-source') || '').trim();
    const focus = state.sourceKeys.includes(activeKey) ? activeKey : state.sourceKeys[0] || activeKey;
    setSources([focus].filter(Boolean));
  });

  const shell = document.getElementById('sharepoint-owner-dash-shell');
  const solo = root.getAttribute('data-solo') === '1';
  const ownerPageUrl = () => {
    if (publicShare) {
      return window.location.href.split('#')[0];
    }
    const url = new URL('sharepoint.php', window.location.href);
    url.search = '';
    url.hash = '';
    url.searchParams.set('view', 'owners');
    return url.toString();
  };

  root.querySelectorAll('[data-no-toggle]').forEach((el) => {
    el.addEventListener('click', (event) => event.stopPropagation());
    el.addEventListener('pointerdown', (event) => event.stopPropagation());
  });

  document.getElementById('sp-owner-open-tab')?.addEventListener('click', (event) => {
    event.stopPropagation();
  });

  document.getElementById('sp-owner-open-window')?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    const features = 'popup=yes,width=1480,height=920,left=40,top=40,menubar=no,toolbar=no,location=yes,status=yes,resizable=yes,scrollbars=yes';
    const win = window.open(ownerPageUrl(), 'riskregister-owner-dash', features);
    if (win) {
      try {
        win.opener = null;
      } catch {
        /* ignore */
      }
      win.focus();
    }
  });

  const shouldLoad = () => solo || !shell || shell.open;

  if (shell && !solo && !publicShare) {
    try {
      const saved = localStorage.getItem(SHELL_KEY);
      if (window.location.hash === '#sharepoint-owner-dash' || saved === '1') {
        shell.open = true;
      } else if (saved === '0') {
        shell.open = false;
      }
    } catch {
      /* ignore */
    }
    shell.addEventListener('toggle', () => {
      try {
        localStorage.setItem(SHELL_KEY, shell.open ? '1' : '0');
      } catch {
        /* ignore */
      }
      if (shell.open && !state.data && !state.loading) load();
    });
  }

  if ((solo || publicShare) && shell) {
    shell.open = true;
    shell.addEventListener('toggle', () => {
      if (!shell.open) shell.open = true;
    });
  }

  syncScopeChips();
  if (shouldLoad()) load();
})();
