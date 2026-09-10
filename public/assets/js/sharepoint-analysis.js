(() => {
  const root = document.getElementById('sharepoint-analysis-page');
  if (!root) return;

  const SOURCES_KEY = 'riskregister_sp_analysis_sources';
  const scopesRoot = document.getElementById('analysis-scopes');
  const exportAllBtn = document.getElementById('analysis-export-all');
  const staleAgeSelect = document.getElementById('analysis-stale-age');

  const state = {
    loading: {},
    data: {},
    expanded: {
      near: new Set(),
      twins: new Set(),
    },
  };

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

  const readSelectedSources = () => {
    const checks = root.querySelectorAll('.analysis-scope-check:checked');
    const keys = Array.from(checks).map((el) => el.value).filter(Boolean);
    if (keys.length) return keys;
    const first = root.querySelector('.analysis-scope-check');
    return first?.value ? [first.value] : [];
  };

  const saveSelectedSources = () => {
    try {
      localStorage.setItem(SOURCES_KEY, JSON.stringify(readSelectedSources()));
    } catch (e) { /* ignore */ }
  };

  const restoreSelectedSources = () => {
    const checks = Array.from(root.querySelectorAll('.analysis-scope-check'));
    if (!checks.length) return;
    let stored = null;
    try {
      const parsed = JSON.parse(localStorage.getItem(SOURCES_KEY) || 'null');
      if (Array.isArray(parsed)) stored = new Set(parsed.map(String));
    } catch (e) { /* ignore */ }
    if (!stored) return;
    checks.forEach((input) => {
      input.checked = stored.has(input.value);
    });
    if (!checks.some((input) => input.checked)) {
      checks[0].checked = true;
    }
  };

  const updateScopesUi = () => {
    const checks = Array.from(root.querySelectorAll('.analysis-scope-check'));
    if (!checks.length) return;
    const selected = checks.filter((el) => el.checked).length;
    const countEl = document.getElementById('analysis-scopes-count');
    if (countEl) countEl.textContent = `${selected} of ${checks.length}`;
    const allBtn = document.getElementById('analysis-scopes-all');
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

  const apiUrl = (action, params = {}) => {
    const qs = new URLSearchParams();
    qs.set('action', action);
    Object.entries(params).forEach(([key, value]) => {
      if (value === undefined || value === null || String(value) === '') return;
      qs.set(key, String(value));
    });
    return `sharepoint.php?${qs.toString()}`;
  };

  const setLoading = (key, el) => {
    state.loading[key] = true;
    if (el) el.innerHTML = '<p class="panel-help">Loading…</p>';
  };

  const setError = (el, message) => {
    if (el) el.innerHTML = `<p class="sp-size-error">${escapeHtml(message || 'Request failed.')}</p>`;
  };

  const fetchAnalysis = async (key, action, params, render) => {
    const el = document.getElementById(`${key}-content`) || document.getElementById(key);
    setLoading(key, el);
    try {
      const sources = readSelectedSources().join(',') || 'all';
      const response = await fetch(apiUrl(action, { sources, ...params }), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok || data.ok === false) {
        setError(el, data.error || `Request failed (${response.status})`);
        return;
      }
      state.data[key] = data;
      render(data, el);
    } catch (err) {
      setError(el, err?.message || 'Network error.');
    } finally {
      state.loading[key] = false;
    }
  };

  const renderKpis = (cards) => `
    <div class="sp-size-kpis analysis-kpis">
      ${cards.map((card) => `
        <div class="sp-size-kpi">
          <span class="sp-size-kpi-label">${escapeHtml(card.label)}</span>
          <span class="sp-size-kpi-value">${escapeHtml(card.value)}</span>
        </div>`).join('')}
    </div>`;

  const renderStaleFiles = (data, el) => {
    const summary = data.summary || {};
    const buckets = summary.buckets || {};
    const bucketOrder = ['recent', '1-3years', '3-5years', '5plus', 'unknown'];
    const maxSpace = Math.max(...bucketOrder.map((k) => Number(buckets[k]?.space) || 0), 1);
    const fillClass = (key) => {
      if (key === 'recent') return 'recent';
      if (key === '1-3years') return 'moderate';
      return 'old';
    };
    const bars = bucketOrder.map((key) => {
      const bucket = buckets[key] || { count: 0, space: 0, label: key };
      const pct = Math.max(bucket.space > 0 ? 4 : 0, Math.round((Number(bucket.space) / maxSpace) * 100));
      return `<div class="age-bucket-bar">
        <span class="age-bucket-label">${escapeHtml(bucket.label || key)}</span>
        <span class="age-bucket-track"><span class="age-bucket-fill ${fillClass(key)}" style="width:${pct}%"></span></span>
        <span class="age-bucket-meta">${escapeHtml(formatBytes(bucket.space))} · ${Number(bucket.count) || 0}</span>
      </div>`;
    }).join('');

    const files = Array.isArray(data.files) ? data.files : [];
    const table = files.length
      ? `<div class="table-wrap"><table class="sp-size-files-table">
          <thead><tr><th>File</th><th>Catalog / Project</th><th>Age</th><th>Size</th><th>Modified</th></tr></thead>
          <tbody>${files.map((file) => {
            const url = String(file.web_url || '');
            const name = url
              ? `<a href="${escapeAttr(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(file.name || '—')}</a>`
              : escapeHtml(file.name || '—');
            return `<tr>
              <td class="sp-size-file-name">${name}<div class="analysis-path-hint">${escapeHtml(file.relative_path || '')}</div></td>
              <td>${escapeHtml(file.catalog_title || '')}<br><span class="analysis-muted">${escapeHtml(file.project_name || '')}</span></td>
              <td>${escapeHtml(file.age_bucket || '')}</td>
              <td class="sp-size-file-bytes">${escapeHtml(formatBytes(file.size_bytes))}</td>
              <td>${escapeHtml(formatModified(file.last_modified))}</td>
            </tr>`;
          }).join('')}</tbody>
        </table></div>`
      : '<p class="sp-size-empty">No stale files match the current threshold.</p>';

    el.innerHTML = `
      ${renderKpis([
        { label: 'Stale files', value: String(summary.total_stale_files || 0) },
        { label: 'Stale space', value: formatBytes(summary.total_stale_space) },
      ])}
      <p class="panel-help">Age distribution across all files (bars). Table lists the largest stale files for cleanup.</p>
      <div class="age-bucket-list">${bars}</div>
      ${table}`;
  };

  const renderFileTypes = (data, el) => {
    const summary = data.summary || {};
    const categories = summary.type_categories || {};
    const catCards = Object.entries(categories).map(([, cat]) => `
      <div class="file-type-category">
        <div class="file-type-category-name">${escapeHtml(cat.label || '')}</div>
        <div class="file-type-category-size">${escapeHtml(formatBytes(cat.space))}</div>
        <div class="file-type-category-count">${Number(cat.count) || 0} files</div>
      </div>`).join('');

    const extensions = Array.isArray(data.top_extensions) ? data.top_extensions : [];
    const table = extensions.length
      ? `<div class="table-wrap"><table class="sp-size-files-table">
          <thead><tr><th>Extension</th><th>Files</th><th>Space</th></tr></thead>
          <tbody>${extensions.map((row) => `
            <tr>
              <td><code>${escapeHtml(row.extension || '(none)')}</code></td>
              <td>${Number(row.count) || 0}</td>
              <td class="sp-size-file-bytes">${escapeHtml(formatBytes(row.space))}</td>
            </tr>`).join('')}</tbody>
        </table></div>`
      : '<p class="sp-size-empty">No files found.</p>';

    el.innerHTML = `
      ${renderKpis([
        { label: 'Total files', value: String(summary.total_files || 0) },
        { label: 'Total space', value: formatBytes(summary.total_space) },
      ])}
      <div class="file-type-grid">${catCards || '<p class="sp-size-empty">No categories.</p>'}</div>
      <h3 class="analysis-subhead">Top extensions by space</h3>
      ${table}`;
  };

  const renderNearDuplicates = (data, el) => {
    const summary = data.summary || {};
    const groups = Array.isArray(data.groups) ? data.groups : [];
    const table = groups.length
      ? `<div class="table-wrap"><table class="sp-size-files-table">
          <thead><tr><th>File</th><th>Variants</th><th>Copies</th><th>Size range</th><th></th></tr></thead>
          <tbody>${groups.map((group) => {
            const key = String(group.file_name || '').toLowerCase();
            const expanded = state.expanded.near.has(key);
            const locations = Array.isArray(group.locations) ? group.locations : [];
            const detail = expanded
              ? `<tr class="sp-duplicates-detail-row"><td colspan="5"><div class="table-wrap"><table class="sp-duplicates-locations-table">
                  <thead><tr><th>Catalog</th><th>Project</th><th>Path</th><th>Size</th></tr></thead>
                  <tbody>${locations.map((loc) => {
                    const url = String(loc.web_url || '');
                    const path = url
                      ? `<a href="${escapeAttr(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(loc.relative_path || '')}</a>`
                      : escapeHtml(loc.relative_path || '—');
                    return `<tr>
                      <td>${escapeHtml(loc.catalog_title || '')}</td>
                      <td>${escapeHtml(loc.project_name || '')}</td>
                      <td class="sp-duplicates-path">${path}</td>
                      <td class="sp-size-file-bytes">${escapeHtml(formatBytes(loc.size_bytes))}</td>
                    </tr>`;
                  }).join('')}</tbody></table></div></td></tr>`
              : '';
            return `<tr>
              <td class="sp-size-file-name">${escapeHtml(group.file_name || '—')}</td>
              <td>${Number(group.size_variants) || 0}</td>
              <td>${Number(group.occurrence_count) || 0}</td>
              <td>${escapeHtml(formatBytes(group.min_size))} – ${escapeHtml(formatBytes(group.max_size))}</td>
              <td><button type="button" class="button ghost" data-near-toggle="${escapeAttr(key)}">${expanded ? 'Hide' : 'Show'}</button></td>
            </tr>${detail}`;
          }).join('')}</tbody>
        </table></div>`
      : '<p class="sp-size-empty">No same-name, different-size files found.</p>';

    el.innerHTML = `
      ${renderKpis([
        { label: 'Near-duplicate sets', value: String(summary.near_duplicate_sets || 0) },
        { label: 'Showing', value: String(summary.groups_returned || 0) },
      ])}
      <p class="panel-help">Same filename with different sizes — often version sprawl. Complements exact duplicates on the heatmap.</p>
      ${table}`;
  };

  const renderProjectBloat = (data, el) => {
    const summary = data.summary || {};
    const projects = Array.isArray(data.projects) ? data.projects : [];
    const table = projects.length
      ? `<div class="table-wrap"><table class="sp-size-files-table">
          <thead><tr><th>Project</th><th>Files</th><th>Total</th><th>Avg</th><th>Tiny</th><th>Pattern</th></tr></thead>
          <tbody>${projects.map((row) => `
            <tr>
              <td>
                <strong>${escapeHtml(row.project_name || '—')}</strong>
                <div class="analysis-muted">${escapeHtml(row.catalog_title || '')}</div>
              </td>
              <td>${Number(row.file_count) || 0}</td>
              <td class="sp-size-file-bytes">${escapeHtml(formatBytes(row.total_bytes))}</td>
              <td>${escapeHtml(formatBytes(row.avg_file_size))}</td>
              <td>${Number(row.tiny_files) || 0}</td>
              <td><span class="bloat-indicator ${escapeAttr(row.pattern || 'balanced')}">${escapeHtml(row.pattern || 'balanced')}</span></td>
            </tr>`).join('')}</tbody>
        </table></div>`
      : '<p class="sp-size-empty">No projects found.</p>';

    el.innerHTML = `
      ${renderKpis([
        { label: 'Projects ranked', value: String(summary.project_count || 0) },
        { label: 'Clutter pattern', value: String(summary.clutter_count || 0) },
        { label: 'Media / large files', value: String(summary.media_count || 0) },
      ])}
      <p class="panel-help"><span class="bloat-indicator clutter">clutter</span> many tiny files · <span class="bloat-indicator media">media</span> few large files · <span class="bloat-indicator balanced">balanced</span></p>
      ${table}`;
  };

  const renderEmptyFolders = (data, el) => {
    const summary = data.summary || {};
    const folders = Array.isArray(data.folders) ? data.folders : [];
    const table = folders.length
      ? `<div class="table-wrap"><table class="sp-size-files-table">
          <thead><tr><th>Folder</th><th>Catalog / Project</th><th>Depth</th><th></th></tr></thead>
          <tbody>${folders.map((folder) => {
            const url = String(folder.web_url || '');
            const link = url
              ? `<a class="button ghost" href="${escapeAttr(url)}" target="_blank" rel="noopener noreferrer">Open</a>`
              : '—';
            return `<tr>
              <td class="sp-size-file-name">${escapeHtml(folder.name || '—')}<div class="analysis-path-hint">${escapeHtml(folder.relative_path || '')}</div></td>
              <td>${escapeHtml(folder.catalog_title || '')}<br><span class="analysis-muted">${escapeHtml(folder.project_name || '')}</span></td>
              <td>${Number(folder.depth) || 0}</td>
              <td>${link}</td>
            </tr>`;
          }).join('')}</tbody>
        </table></div>`
      : '<p class="sp-size-empty">No empty folders found.</p>';

    el.innerHTML = `
      ${renderKpis([
        { label: 'Empty folders', value: String(summary.empty_folder_count || 0) },
        { label: 'Catalogs affected', value: String(summary.catalogs_affected || 0) },
      ])}
      <p class="panel-help">Folders with no files underneath (may still contain empty subfolders).</p>
      ${table}`;
  };

  const renderPathTwins = (data, el) => {
    const summary = data.summary || {};
    if (summary.needs_multiple_catalogs) {
      el.innerHTML = '<p class="sp-size-empty">Select at least two catalogs to find cross-catalog path twins.</p>';
      return;
    }
    const twins = Array.isArray(data.twins) ? data.twins : [];
    const table = twins.length
      ? `<div class="table-wrap"><table class="sp-size-files-table">
          <thead><tr><th>Path</th><th>Catalogs</th><th>Total</th><th>Size drift</th><th></th></tr></thead>
          <tbody>${twins.map((twin) => {
            const key = String(twin.relative_path || '').toLowerCase();
            const expanded = state.expanded.twins.has(key);
            const locations = Array.isArray(twin.locations) ? twin.locations : [];
            const detail = expanded
              ? `<tr class="sp-duplicates-detail-row"><td colspan="5"><div class="table-wrap"><table class="sp-duplicates-locations-table">
                  <thead><tr><th>Catalog</th><th>Project</th><th>Size</th><th>Modified</th></tr></thead>
                  <tbody>${locations.map((loc) => {
                    const url = String(loc.web_url || '');
                    const title = url
                      ? `<a href="${escapeAttr(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(loc.catalog_title || '')}</a>`
                      : escapeHtml(loc.catalog_title || '');
                    return `<tr>
                      <td>${title}</td>
                      <td>${escapeHtml(loc.project_name || '')}</td>
                      <td class="sp-size-file-bytes">${escapeHtml(formatBytes(loc.size_bytes))}</td>
                      <td>${escapeHtml(formatModified(loc.last_modified))}</td>
                    </tr>`;
                  }).join('')}</tbody></table></div></td></tr>`
              : '';
            return `<tr>
              <td class="sp-size-file-name">${escapeHtml(twin.relative_path || '—')}</td>
              <td>${Number(twin.catalog_count) || 0}</td>
              <td class="sp-size-file-bytes">${escapeHtml(formatBytes(twin.total_bytes))}</td>
              <td>${escapeHtml(formatBytes(twin.size_drift))}</td>
              <td><button type="button" class="button ghost" data-twin-toggle="${escapeAttr(key)}">${expanded ? 'Hide' : 'Show'}</button></td>
            </tr>${detail}`;
          }).join('')}</tbody>
        </table></div>`
      : '<p class="sp-size-empty">No identical paths found across catalogs.</p>';

    el.innerHTML = `
      ${renderKpis([
        { label: 'Twin paths', value: String(summary.twin_paths || 0) },
      ])}
      <p class="panel-help">Same relative path in multiple catalogs — often copied or moved project trees.</p>
      ${table}`;
  };

  const loadAll = () => {
    const age = staleAgeSelect?.value || '1year';
    fetchAnalysis('stale-files', 'analysis_stale_files', { age }, renderStaleFiles);
    fetchAnalysis('file-types', 'analysis_file_types', {}, renderFileTypes);
    fetchAnalysis('near-duplicates', 'analysis_near_duplicates', {}, renderNearDuplicates);
    fetchAnalysis('project-bloat', 'analysis_project_bloat', {}, renderProjectBloat);
    fetchAnalysis('empty-folders', 'analysis_empty_folders', {}, renderEmptyFolders);
    fetchAnalysis('path-twins', 'analysis_path_twins', {}, renderPathTwins);
  };

  const exportAnalysis = (type) => {
    const sources = readSelectedSources().join(',') || 'all';
    const age = staleAgeSelect?.value || '1year';
    const url = apiUrl('export_analysis', { type, sources, age });
    window.location.href = url;
  };

  scopesRoot?.addEventListener('change', (event) => {
    if (!event.target.classList.contains('analysis-scope-check')) return;
    const checks = root.querySelectorAll('.analysis-scope-check:checked');
    if (!checks.length) {
      event.target.checked = true;
      return;
    }
    saveSelectedSources();
    updateScopesUi();
    loadAll();
  });

  document.getElementById('analysis-scopes-all')?.addEventListener('click', () => {
    root.querySelectorAll('.analysis-scope-check').forEach((el) => {
      el.checked = true;
    });
    saveSelectedSources();
    updateScopesUi();
    loadAll();
  });

  staleAgeSelect?.addEventListener('change', () => {
    const age = staleAgeSelect.value || '1year';
    fetchAnalysis('stale-files', 'analysis_stale_files', { age }, renderStaleFiles);
  });

  exportAllBtn?.addEventListener('click', () => exportAnalysis('all'));

  root.addEventListener('click', (event) => {
    const exportBtn = event.target.closest('[data-export]');
    if (exportBtn) {
      exportAnalysis(exportBtn.getAttribute('data-export') || 'all');
      return;
    }
    const nearBtn = event.target.closest('[data-near-toggle]');
    if (nearBtn) {
      const key = nearBtn.getAttribute('data-near-toggle') || '';
      if (state.expanded.near.has(key)) state.expanded.near.delete(key);
      else state.expanded.near.add(key);
      if (state.data['near-duplicates']) {
        renderNearDuplicates(state.data['near-duplicates'], document.getElementById('near-duplicates-content'));
      }
      return;
    }
    const twinBtn = event.target.closest('[data-twin-toggle]');
    if (twinBtn) {
      const key = twinBtn.getAttribute('data-twin-toggle') || '';
      if (state.expanded.twins.has(key)) state.expanded.twins.delete(key);
      else state.expanded.twins.add(key);
      if (state.data['path-twins']) {
        renderPathTwins(state.data['path-twins'], document.getElementById('path-twins-content'));
      }
    }
  });

  restoreSelectedSources();
  updateScopesUi();
  loadAll();
})();
