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
        ]
          .filter(Boolean)
          .join(' · ');
        return `<li>
          <button type="button" class="sp-od-project" data-project-name="${escapeHtml(project.project_name)}" data-source-key="${escapeHtml(project.source_key)}" data-folder-url="${escapeHtml(project.folder_url || '')}">
            <strong>${highlightQuery(project.project_name)}</strong>
            <span>${escapeHtml(meta)}</span>
          </button>
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
    try {
      const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
      if (Array.isArray(raw) && raw.length) {
        const valid = raw.map(String).filter((key) => titleByKey[key]);
        if (valid.length) return valid;
      }
    } catch {
      /* ignore */
    }
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
  };

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
    if (countEl) {
      countEl.textContent = total ? `${selected} of ${total}` : '0';
    }
    if (allBtn) {
      const allOn = total > 0 && selected === total;
      allBtn.classList.toggle('is-active', allOn);
      allBtn.disabled = allOn;
      allBtn.textContent = allOn ? 'All selected' : 'Select all';
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

  const buildView = () => {
    const projects = filteredProjects();
    const hues = hueByOwner();
    const ownerMap = {};
    const byPeriod = {};
    let unknownDate = 0;

    projects.forEach((project) => {
      const key = project.owner_key;
      if (!ownerMap[key]) {
        ownerMap[key] = {
          key,
          name: project.owner_name,
          initials: (state.data.owners.find((o) => o.key === key) || {}).initials || '?',
          hue: hues[key] ?? 200,
          project_count: 0,
          unknown_date_count: 0,
          first_created: '',
          last_created: '',
          months: {},
          projects: [],
        };
      }
      const owner = ownerMap[key];
      owner.project_count += 1;
      owner.projects.push(project);
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
    });

    const owners = Object.values(ownerMap).sort((a, b) => b.project_count - a.project_count || a.name.localeCompare(b.name));
    const visibleOwners = owners;

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

    return {
      projects,
      owners,
      visibleOwners,
      selected,
      periods: visiblePeriods,
      byPeriod,
      unknownDate,
      busiestPeriod,
      total: projects.length,
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
    const assigned = view.owners.filter((o) => o.key !== '_unassigned').reduce((sum, o) => sum + o.project_count, 0);
    const cards = [
      { label: 'Projects', value: view.total, hint: state.year === 'all' ? 'in selected folders' : `created in ${state.year}` },
      { label: 'Owners', value: view.owners.length, hint: `${assigned} assigned` },
      {
        label: 'Top owner',
        value: top ? top.project_count : 0,
        hint: top ? top.name : 'No owners yet',
        accent: top ? ownerColor(top.hue) : '',
      },
      {
        label: `Busiest ${state.grain}`,
        value: view.busiestPeriod.count,
        hint: view.busiestPeriod.key ? formatPeriod(view.busiestPeriod.key, state.grain) : '—',
      },
    ];
    return `<div class="sp-od-kpis">${cards
      .map(
        (card) => `<article class="sp-od-kpi"${card.accent ? ` style="--od-accent:${card.accent}"` : ''}>
          <span class="sp-od-kpi-label">${escapeHtml(card.label)}</span>
          <strong class="sp-od-kpi-value">${escapeHtml(String(card.value))}</strong>
          <span class="sp-od-kpi-hint">${escapeHtml(card.hint)}</span>
        </article>`
      )
      .join('')}</div>`;
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
        <p>${selected ? 'Showing this owner’s folders by period. Click a bar segment to list those projects, or click the person again to return to the stacked view.' : 'Stacked by owner. Click a person to focus them, or click a bar segment to list those project folders.'}</p>
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
    const max = view.visibleOwners[0]?.project_count || 1;
    if (!view.visibleOwners.length) {
      return `<div class="sp-od-empty">No people or projects match this filter.</div>`;
    }
    return `<div class="sp-od-board">
      <div class="sp-od-board-head">
        <h3>Owner leaderboard</h3>
      </div>
      <ol class="sp-od-ranks">
        ${view.visibleOwners
          .map((owner, index) => {
            const pct = Math.max(4, (owner.project_count / max) * 100);
            const active = owner.key === state.ownerKey ? ' is-active' : '';
            return `<li>
              <button type="button" class="sp-od-rank${active}" data-owner-key="${escapeHtml(owner.key)}">
                <span class="sp-od-rank-n">${index + 1}</span>
                <span class="sp-od-avatar sp-od-avatar-sm" style="--hue:${owner.hue}; --share:1"><span>${escapeHtml(owner.initials)}</span></span>
                <span class="sp-od-rank-copy">
                  <strong>${highlightQuery(owner.name)}</strong>
                  <span>${owner.project_count} project${owner.project_count === 1 ? '' : 's'}${owner.last_created ? ` · last ${escapeHtml(formatDay(owner.last_created))}` : ''}</span>
                </span>
                <span class="sp-od-rank-bar"><i style="width:${pct}%; background:${ownerColor(owner.hue)}"></i></span>
                <b>${owner.project_count}</b>
              </button>
            </li>`;
          })
          .join('')}
      </ol>
    </div>`;
  };

  const renderHeatmap = (view) => {
    const rows = view.visibleOwners.slice(0, HEATMAP_OWNERS);
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
        <p>Darker cells mean more project folders created in that period. Click a cell to list those project folders.${wide ? ' Scroll sideways to see every year.' : ''}</p>
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
          <p>${owner.project_count} project folder${owner.project_count === 1 ? '' : 's'} · ${escapeHtml(sourceBits.join(', ') || 'Selected catalogs')}</p>
        </div>
        <button type="button" class="button ghost" id="sp-owner-clear" title="Clear owner filter">Clear</button>
      </div>
      <div class="sp-od-detail-meta">
        <span><b>${owner.first_created ? formatDay(owner.first_created) : '—'}</b> first</span>
        <span><b>${owner.last_created ? formatDay(owner.last_created) : '—'}</b> latest</span>
        <span><b>${owner.unknown_date_count}</b> undated</span>
      </div>
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
                            <button type="button" class="sp-od-project" data-project-name="${escapeHtml(project.project_name)}" data-source-key="${escapeHtml(project.source_key)}">
                              <strong>${highlightQuery(project.project_name)}</strong>
                              <span>${escapeHtml(project.source_title || '')}${project.date_created ? ` · ${escapeHtml(formatDay(project.date_created))}` : ''}</span>
                            </button>
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
      ${renderPortraitRow(view)}
      <div class="sp-od-grid">
        ${renderLeaderboard(view)}
        ${renderDetail(view)}
      </div>
      ${renderChart(view)}
      ${renderHeatmap(view)}
    `;
    bindBody();
  };

  const toggleOwner = (key) => {
    state.ownerKey = state.ownerKey === key ? '' : key;
    render();
  };

  const bindBody = () => {
    body.querySelectorAll('[data-owner-key]').forEach((el) => {
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
  };

  const load = () => {
    const keys = state.sourceKeys.length ? state.sourceKeys : availableSources.map((s) => s.source_key).filter(Boolean);
    state.loading = true;
    state.error = '';
    render();
    fetch(ownerApiUrl('owner_stats', { sources: keys.length ? keys.join(',') : 'all' }), {
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
