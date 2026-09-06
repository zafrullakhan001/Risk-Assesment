(() => {
  const Fuzzy = window.FuzzySearch;
  const escapeHtml = (value) =>
    String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

  /** Schedule work after the browser paints so typing is not blocked. */
  const debouncePaint = (fn, waitMs) => {
    let timer = 0;
    let raf = 0;
    const cancel = () => {
      window.clearTimeout(timer);
      timer = 0;
      if (raf) {
        window.cancelAnimationFrame(raf);
        raf = 0;
      }
    };
    const schedule = (...args) => {
      cancel();
      raf = window.requestAnimationFrame(() => {
        raf = 0;
        timer = window.setTimeout(() => {
          timer = 0;
          fn(...args);
        }, waitMs);
      });
    };
    schedule.cancel = cancel;
    schedule.flush = (...args) => {
      cancel();
      fn(...args);
    };
    return schedule;
  };

  const bindWorkspaceDialog = (dialog) => {
    if (!dialog || dialog.dataset.workspaceBound === '1') return;
    dialog.dataset.workspaceBound = '1';

    const head = dialog.querySelector('.sp-dialog-drag-handle');
    const maximizeBtn =
      dialog.querySelector('.sp-dialog-maximize') ||
      document.getElementById(`${dialog.id}-maximize`);
    const storageKey = `riskregister_sp_workspace_${dialog.id || 'dialog'}`;

    let maximized = false;
    let savedRect = null;
    let lastRect = null;
    let drag = null;
    let persistTimer = 0;

    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

    const isUsableRect = (rect) =>
      !!rect &&
      Number.isFinite(Number(rect.left)) &&
      Number.isFinite(Number(rect.top)) &&
      Number(rect.width) >= 420 &&
      Number(rect.height) >= 320;

    const currentRect = () => {
      const box = dialog.getBoundingClientRect();
      return {
        left: box.left,
        top: box.top,
        width: box.width,
        height: box.height,
      };
    };

    const rememberRect = (rect = null) => {
      const next = rect || (dialog.open && !maximized ? currentRect() : null);
      if (isUsableRect(next)) lastRect = next;
    };

    const readLayout = () => {
      try {
        const raw = JSON.parse(localStorage.getItem(storageKey) || 'null');
        return raw && typeof raw === 'object' ? raw : null;
      } catch {
        return null;
      }
    };

    const persistLayout = () => {
      const rect = lastRect || savedRect;
      const payload = {
        maximized,
        left: rect?.left,
        top: rect?.top,
        width: rect?.width,
        height: rect?.height,
      };
      if (!payload.maximized && !isUsableRect(payload)) return;
      try {
        localStorage.setItem(storageKey, JSON.stringify(payload));
      } catch {
        /* ignore */
      }
    };

    const schedulePersist = () => {
      window.clearTimeout(persistTimer);
      persistTimer = window.setTimeout(persistLayout, 120);
    };

    const applyRect = (rect) => {
      const maxLeft = Math.max(0, window.innerWidth - 120);
      const maxTop = Math.max(0, window.innerHeight - 80);
      const left = clamp(rect.left, -40, maxLeft);
      const top = clamp(rect.top, 0, maxTop);
      const width = clamp(rect.width, 420, window.innerWidth);
      const height = clamp(rect.height, 320, window.innerHeight);
      dialog.classList.add('is-placed');
      dialog.style.transform = 'none';
      dialog.style.right = '';
      dialog.style.bottom = '';
      dialog.style.left = `${Math.round(left)}px`;
      dialog.style.top = `${Math.round(top)}px`;
      dialog.style.width = `${Math.round(width)}px`;
      dialog.style.height = `${Math.round(height)}px`;
      rememberRect({ left, top, width, height });
      schedulePersist();
    };

    const syncMaximizeBtn = () => {
      if (!maximizeBtn) return;
      maximizeBtn.setAttribute('aria-pressed', maximized ? 'true' : 'false');
      maximizeBtn.title = maximized ? 'Restore size' : 'Maximize';
      maximizeBtn.setAttribute('aria-label', maximized ? 'Restore dialog size' : 'Maximize dialog');
      maximizeBtn.textContent = maximized ? '❐' : '⛶';
    };

    const centerDefault = () => {
      dialog.classList.remove('is-maximized', 'is-placed');
      dialog.style.transform = '';
      dialog.style.left = '';
      dialog.style.top = '';
      dialog.style.width = '';
      dialog.style.height = '';
      dialog.style.right = '';
      dialog.style.bottom = '';
      window.requestAnimationFrame(() => {
        if (!dialog.open || maximized) return;
        applyRect(currentRect());
      });
    };

    const setMaximized = (next) => {
      if (next) {
        if (!maximized) {
          if (dialog.open) rememberRect();
          savedRect = lastRect || savedRect;
        }
        maximized = true;
        dialog.classList.add('is-maximized', 'is-placed');
        dialog.style.transform = 'none';
        dialog.style.left = '0px';
        dialog.style.top = '0px';
        dialog.style.width = '100vw';
        dialog.style.height = '100vh';
        dialog.style.right = '0px';
        dialog.style.bottom = '0px';
      } else {
        maximized = false;
        dialog.classList.remove('is-maximized');
        if (isUsableRect(savedRect) || isUsableRect(lastRect)) applyRect(savedRect || lastRect);
        else centerDefault();
      }
      syncMaximizeBtn();
      schedulePersist();
    };

    const restoreLayout = () => {
      const saved = readLayout();
      const rect = isUsableRect(saved)
        ? { left: saved.left, top: saved.top, width: saved.width, height: saved.height }
        : lastRect;
      if (isUsableRect(rect)) {
        savedRect = rect;
        lastRect = rect;
      }
      if (saved?.maximized || maximized) {
        setMaximized(true);
        return;
      }
      if (isUsableRect(rect)) {
        maximized = false;
        dialog.classList.remove('is-maximized');
        applyRect(rect);
        syncMaximizeBtn();
        return;
      }
      centerDefault();
    };

    maximizeBtn?.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      setMaximized(!maximized);
    });

    head?.addEventListener('pointerdown', (event) => {
      if (event.button !== 0) return;
      if (event.target.closest('button, a, input, select, textarea, label')) return;
      if (maximized) return;
      const rect = currentRect();
      drag = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        originLeft: rect.left,
        originTop: rect.top,
        width: rect.width,
        height: rect.height,
      };
      dialog.classList.add('is-dragging', 'is-placed');
      try {
        head.setPointerCapture(event.pointerId);
      } catch {
        /* ignore */
      }
      event.preventDefault();
    });

    const onPointerMove = (event) => {
      if (!drag || event.pointerId !== drag.pointerId) return;
      applyRect({
        left: drag.originLeft + (event.clientX - drag.startX),
        top: drag.originTop + (event.clientY - drag.startY),
        width: drag.width,
        height: drag.height,
      });
    };

    const onPointerUp = (event) => {
      if (!drag || event.pointerId !== drag.pointerId) return;
      drag = null;
      dialog.classList.remove('is-dragging');
      rememberRect();
      persistLayout();
      try {
        head?.releasePointerCapture(event.pointerId);
      } catch {
        /* ignore */
      }
    };

    head?.addEventListener('pointermove', onPointerMove);
    head?.addEventListener('pointerup', onPointerUp);
    head?.addEventListener('pointercancel', onPointerUp);

    head?.addEventListener('dblclick', (event) => {
      if (event.target.closest('button, a, input, select, textarea, label')) return;
      setMaximized(!maximized);
    });

    if (typeof ResizeObserver === 'function') {
      const resizeObserver = new ResizeObserver(() => {
        if (!dialog.open || maximized || drag) return;
        rememberRect();
        schedulePersist();
      });
      resizeObserver.observe(dialog);
    }

    dialog.addEventListener('close', () => {
      drag = null;
      dialog.classList.remove('is-dragging');
      persistLayout();
      if (maximized) dialog.classList.remove('is-maximized');
    });

    dialog.addEventListener(
      'keydown',
      (event) => {
        if (event.key !== 'Escape' || event.isComposing || !dialog.open) return;
        event.preventDefault();
        event.stopPropagation();
        dialog.close();
      },
      true
    );

    dialog.__spPrepareWorkspace = () => {
      restoreLayout();
    };
  };

  const parseItemDate = (value) => {
    const raw = String(value ?? '').trim();
    if (!raw) return null;
    if (/^\d{4}-\d{2}-\d{2}/.test(raw)) {
      const iso = new Date(raw);
      if (!Number.isNaN(iso.getTime())) return iso;
    }
    const us = raw.match(
      /^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM)?)?/i
    );
    if (us) {
      let hour = Number(us[4] || 0);
      const minute = Number(us[5] || 0);
      const second = Number(us[6] || 0);
      const ampm = String(us[7] || '').toUpperCase();
      if (ampm === 'PM' && hour < 12) hour += 12;
      if (ampm === 'AM' && hour === 12) hour = 0;
      const parsed = new Date(Number(us[3]), Number(us[1]) - 1, Number(us[2]), hour, minute, second);
      if (!Number.isNaN(parsed.getTime())) return parsed;
    }
    const fallback = new Date(raw);
    return Number.isNaN(fallback.getTime()) ? null : fallback;
  };

  const formatModified = (value) => {
    const raw = String(value ?? '').trim();
    if (!raw) return '—';
    const date = parseItemDate(raw);
    if (date) {
      return date.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      });
    }
    return raw;
  };

  const formatActivityDay = (date) => {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) return '—';
    return date.toLocaleDateString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  };

  const calendarDayDiff = (from, to) => {
    if (!(from instanceof Date) || !(to instanceof Date)) return null;
    const start = Date.UTC(from.getFullYear(), from.getMonth(), from.getDate());
    const end = Date.UTC(to.getFullYear(), to.getMonth(), to.getDate());
    return Math.max(0, Math.round((end - start) / 86400000));
  };

  const formatSize = (bytes) => {
    const n = Number(bytes);
    if (!Number.isFinite(n) || n <= 0) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = n;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
      value /= 1024;
      unit += 1;
    }
    const digits = unit === 0 || value >= 10 ? 0 : 1;
    return `${value.toFixed(digits)} ${units[unit]}`;
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

  const normalizeRelPath = (value) =>
    String(value || '')
      .replace(/\\/g, '/')
      .replace(/^\/+|\/+$/g, '');

  const parentRelPath = (path) => {
    const parts = normalizeRelPath(path).split('/').filter(Boolean);
    if (parts.length <= 1) return '';
    return parts.slice(0, -1).join('/');
  };

  const SEARCH_PREF = {
    wordMode: 'riskregister_sp_search_word_mode',
    fuzzy: 'riskregister_sp_search_fuzzy',
    compareDensity: 'riskregister_sp_compare_density',
    listDensity: 'riskregister_sp_list_density',
  };

  const readDialogDensity = () => {
    try {
      return localStorage.getItem(SEARCH_PREF.compareDensity) === 'comfort' ? 'comfort' : 'compact';
    } catch {
      return 'compact';
    }
  };

  const applyDialogDensity = (targetDialog, targetWrap, next) => {
    const density = next === 'comfort' ? 'comfort' : 'compact';
    const dialogs = [targetDialog, document.getElementById('sharepoint-project-dialog'), document.getElementById('sharepoint-compare-dialog')].filter(
      (el, index, list) => el && list.indexOf(el) === index
    );
    dialogs.forEach((el) => {
      el.classList.toggle('is-compact-chrome', density === 'compact');
      el.setAttribute('data-density', density);
      el.querySelectorAll('.sp-view-btn[data-density]').forEach((btn) => {
        const active = btn.getAttribute('data-density') === density;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    });
    try {
      localStorage.setItem(SEARCH_PREF.compareDensity, density);
    } catch {
      /* ignore */
    }
    return density;
  };

  const readListDensity = () => {
    try {
      return localStorage.getItem(SEARCH_PREF.listDensity) === 'comfort' ? 'comfort' : 'compact';
    } catch {
      return 'compact';
    }
  };

  const applyListDensity = (next) => {
    const density = next === 'comfort' ? 'comfort' : 'compact';
    const card = document.getElementById('sharepoint-table-card');
    if (card) {
      card.classList.toggle('is-compact-rows', density === 'compact');
      card.setAttribute('data-density', density);
      card.querySelectorAll('.sp-view-btn[data-list-density]').forEach((btn) => {
        const active = btn.getAttribute('data-list-density') === density;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    }
    try {
      localStorage.setItem(SEARCH_PREF.listDensity, density);
    } catch {
      /* ignore */
    }
    return density;
  };

  const bindListDensityToggle = () => {
    const card = document.getElementById('sharepoint-table-card');
    if (!card || card.dataset.densityBound === '1') return;
    card.dataset.densityBound = '1';
    card.querySelectorAll('.sp-view-btn[data-list-density]').forEach((btn) => {
      btn.addEventListener('click', () => {
        applyListDensity(btn.getAttribute('data-list-density') || 'compact');
      });
    });
    applyListDensity(readListDensity());
  };

  const readSearchPrefs = () => ({
    wordMode: localStorage.getItem(SEARCH_PREF.wordMode) === 'or' ? 'or' : 'and',
    fuzzy: localStorage.getItem(SEARCH_PREF.fuzzy) === '1',
  });

  const writeSearchPrefs = (prefs) => {
    localStorage.setItem(SEARCH_PREF.wordMode, prefs.wordMode === 'or' ? 'or' : 'and');
    localStorage.setItem(SEARCH_PREF.fuzzy, prefs.fuzzy ? '1' : '0');
  };

  const itemSearchFields = (item) => {
    const name = String(item?.name || '');
    const path = String(item?.relative_path || '');
    const meta = resolveMeta(item);
    const ext = fileExtension(name);
    return [
      { text: name, sourceLabel: 'Name', sourceName: 'name' },
      { text: path, sourceLabel: 'Path', sourceName: 'path' },
      { text: ext, sourceLabel: 'Extension', sourceName: 'ext' },
      { text: ext ? `.${ext}` : '', sourceLabel: 'Extension', sourceName: 'ext_dot' },
      { text: meta.label, sourceLabel: 'Type', sourceName: 'type_label' },
      { text: String(item?.item_type || ''), sourceLabel: 'Type', sourceName: 'item_type' },
      { text: String(item?.modified_by || ''), sourceLabel: 'Modified by', sourceName: 'modified_by' },
      { text: String(item?.person || ''), sourceLabel: 'Created By', sourceName: 'person' },
      { text: String(item?.mime_type || ''), sourceLabel: 'MIME', sourceName: 'mime' },
    ];
  };

  /** @returns {{ matched: boolean, score: number, kind?: string }} */
  const scoreItemQuery = (item, query, options = {}) => {
    const words = Fuzzy?.getSearchWords
      ? Fuzzy.getSearchWords(query)
      : String(query || '')
          .toLowerCase()
          .split(/\s+/)
          .filter(Boolean);
    if (!words.length) return { matched: true, score: 100, kind: 'exact' };

    const prefs = { ...readSearchPrefs(), ...options };
    const mode = prefs.wordMode === 'or' ? 'or' : 'and';
    const fuzzyOn = !!prefs.fuzzy;

    if (Fuzzy?.scoreLabeledFieldsAgainstWords) {
      return Fuzzy.scoreLabeledFieldsAgainstWords(itemSearchFields(item), words, mode, fuzzyOn);
    }

    const blob = itemSearchFields(item)
      .map((field) => String(field.text || '').toLowerCase())
      .join(' ');
    const matched =
      mode === 'or' ? words.some((word) => blob.includes(word)) : words.every((word) => blob.includes(word));
    return { matched, score: matched ? 80 : 0, kind: matched ? 'contains' : 'none' };
  };

  const matchesQuery = (item, query, options = {}) => scoreItemQuery(item, query, options).matched;

  const isFolderItem = (item) => String(item?.item_type || '').toLowerCase() === 'folder';

  const isRootProjectFolder = (item, projectName) => {
    if (!isFolderItem(item)) return false;
    const name = String(item?.name || '').trim();
    const path = String(item?.relative_path || '').trim();
    const project = String(projectName || '').trim();
    return path === '' || path === name || (project !== '' && (path === project || name === project));
  };

  const computeProjectActivityStats = (items, projectName = '') => {
    const list = Array.isArray(items) ? items : [];
    const userMap = new Map();
    let firstCreated = null;
    let rootCreated = null;
    let lastUpdated = null;

    list.forEach((item) => {
      [item?.person, item?.modified_by].forEach((name) => {
        const display = String(name || '').trim();
        if (!display) return;
        const key = display.toLowerCase();
        if (!userMap.has(key)) userMap.set(key, display);
      });

      const created = parseItemDate(item?.date_created);
      if (created) {
        if (!firstCreated || created < firstCreated) firstCreated = created;
        if (isRootProjectFolder(item, projectName) && (!rootCreated || created < rootCreated)) {
          rootCreated = created;
        }
      }

      const updated = parseItemDate(item?.last_modified) || created;
      if (updated && (!lastUpdated || updated > lastUpdated)) lastUpdated = updated;
    });

    const createdAt = rootCreated || firstCreated;
    const spanDays = calendarDayDiff(createdAt, lastUpdated);
    const users = [...userMap.values()].sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }));

    return {
      userCount: users.length,
      users,
      createdAt,
      lastUpdated,
      spanDays,
    };
  };

  const renderProjectActivityStats = (statsEl, stats) => {
    if (!statsEl) return;
    if (!stats || (!stats.userCount && !stats.createdAt && !stats.lastUpdated)) {
      statsEl.hidden = true;
      statsEl.innerHTML = '';
      return;
    }

    const spanLabel =
      stats.spanDays === null
        ? '—'
        : stats.spanDays === 0
          ? 'same day'
          : stats.spanDays === 1
            ? '1 day'
            : `${stats.spanDays} days`;

    const userTitle = stats.users.length ? stats.users.join(', ') : 'No creators or modifiers recorded';
    const chips = [
      {
        label: 'Users',
        value: String(stats.userCount),
        title: userTitle,
      },
      {
        label: 'Created',
        value: formatActivityDay(stats.createdAt),
        title: stats.createdAt ? `First created ${formatModified(stats.createdAt.toISOString())}` : 'No created date',
      },
      {
        label: 'Last update',
        value: formatActivityDay(stats.lastUpdated),
        title: stats.lastUpdated
          ? `Last updated ${formatModified(stats.lastUpdated.toISOString())}`
          : 'No modified date',
      },
      {
        label: 'Span',
        value: spanLabel,
        title:
          stats.spanDays === null
            ? 'Need both a created and updated date'
            : `Days from first creation to last update: ${spanLabel}`,
      },
    ];

    statsEl.innerHTML = chips
      .map(
        (chip) =>
          `<span class="sp-project-stat-chip" title="${escapeHtml(chip.title)}">
            <span class="sp-project-stat-label">${escapeHtml(chip.label)}</span>
            <strong class="sp-project-stat-value">${escapeHtml(chip.value)}</strong>
          </span>`
      )
      .join('');
    statsEl.hidden = false;
  };

  const normalizeExtList = (ext) => {
    if (ext == null || ext === '') return [];
    if (ext instanceof Set) {
      return [...ext].map((value) => String(value || '').toLowerCase().replace(/^\./, '')).filter(Boolean);
    }
    if (Array.isArray(ext)) {
      return ext.map((value) => String(value || '').toLowerCase().replace(/^\./, '')).filter(Boolean);
    }
    const one = String(ext).toLowerCase().replace(/^\./, '').trim();
    return one ? [one] : [];
  };

  const countExtensions = (items) => {
    const counts = new Map();
    (items || []).forEach((item) => {
      if (isFolderItem(item)) return;
      const ext = fileExtension(item.name);
      if (!ext) return;
      counts.set(ext, (counts.get(ext) || 0) + 1);
    });
    return counts;
  };

  const formatExtChipLabel = (ext, count) => `.${ext} (${count})`;

  const syncExtChips = (root, items, selectedExts) => {
    if (!root) return;
    const counts = countExtensions(items);
    root.querySelectorAll('.sp-dialog-chip[data-ext]').forEach((chip) => {
      const ext = String(chip.getAttribute('data-ext') || '')
        .toLowerCase()
        .replace(/^\./, '');
      const count = counts.get(ext) || 0;
      const active = selectedExts.has(ext);
      chip.textContent = formatExtChipLabel(ext, count);
      chip.classList.toggle('is-active', active);
      chip.setAttribute('aria-pressed', active ? 'true' : 'false');
      chip.disabled = count === 0 && !active;
      chip.title = count === 0 ? `No .${ext} files in this project` : `Toggle .${ext} filter (${count} file${count === 1 ? '' : 's'})`;
    });
  };

  const passesKindExt = (item, kind, ext) => {
    const folder = isFolderItem(item);
    if (kind === 'files' && folder) return false;
    if (kind === 'folders' && !folder) return false;

    const exts = normalizeExtList(ext);
    if (exts.length === 0) return true;
    // Extension filters apply to files only; tree view keeps parent folders via children.
    if (folder) return false;
    return exts.includes(fileExtension(item.name));
  };

  const collectFolderPaths = (items) => {
    const paths = [];
    (items || []).forEach((item) => {
      if (!isFolderItem(item)) return;
      const path = normalizeRelPath(item.relative_path || item.name || '');
      if (path) paths.push(path.toLowerCase());
    });
    return paths;
  };

  const buildTreeNodes = (items) => {
    const byPath = new Map();
    const roots = [];
    (items || []).forEach((item) => {
      const path = normalizeRelPath(item.relative_path || item.name || '');
      if (!path) return;
      byPath.set(path.toLowerCase(), {
        item,
        path,
        children: [],
      });
    });

    byPath.forEach((node) => {
      const parent = parentRelPath(node.path);
      if (parent && byPath.has(parent.toLowerCase())) {
        byPath.get(parent.toLowerCase()).children.push(node);
      } else {
        roots.push(node);
      }
    });

    const sortNodes = (list) => {
      list.sort((a, b) => {
        const af = isFolderItem(a.item) ? 0 : 1;
        const bf = isFolderItem(b.item) ? 0 : 1;
        if (af !== bf) return af - bf;
        return String(a.item.name || '').localeCompare(String(b.item.name || ''), undefined, {
          sensitivity: 'base',
        });
      });
      list.forEach((child) => sortNodes(child.children));
    };
    sortNodes(roots);
    return roots;
  };

  const filterTree = (nodes, kind, ext, query, options = {}) => {
    const out = [];
    nodes.forEach((node) => {
      const filteredChildren = filterTree(node.children, kind, ext, query, options);
      const selfMatch = passesKindExt(node.item, kind, ext) && matchesQuery(node.item, query, options);
      const keepFolderForChildren = isFolderItem(node.item) && filteredChildren.length > 0;
      const keep = selfMatch || keepFolderForChildren;

      if (!keep) return;
      out.push({
        item: node.item,
        path: node.path,
        children: isFolderItem(node.item) ? filteredChildren : [],
        selfMatch,
      });
    });
    return out;
  };

  const flattenFiltered = (items, kind, ext, query, options = {}) =>
    (items || []).filter((item) => passesKindExt(item, kind, ext) && matchesQuery(item, query, options));

  /**
   * Wire AND/OR + Fuzzy toggles inside a dialog search panel.
   * Prefs are shared with the main SharePoint catalog search.
   */
  const bindDialogSearchModes = (root, onChange) => {
    if (!root) return () => readSearchPrefs();

    const wordModeGroup = root.querySelector('.sp-dialog-word-mode');
    const fuzzyBtn = root.querySelector('.sp-dialog-fuzzy');

    const syncUi = (query = '') => {
      const prefs = readSearchPrefs();
      const words = Fuzzy?.getSearchWords
        ? Fuzzy.getSearchWords(query)
        : String(query || '')
            .toLowerCase()
            .split(/\s+/)
            .filter(Boolean);
      if (wordModeGroup) wordModeGroup.hidden = words.length <= 1;
      wordModeGroup?.querySelectorAll('[data-word-mode]').forEach((btn) => {
        const active = btn.getAttribute('data-word-mode') === prefs.wordMode;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      if (fuzzyBtn) {
        fuzzyBtn.classList.toggle('is-active', prefs.fuzzy);
        fuzzyBtn.setAttribute('aria-pressed', prefs.fuzzy ? 'true' : 'false');
      }
      return prefs;
    };

    wordModeGroup?.querySelectorAll('[data-word-mode]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const prefs = readSearchPrefs();
        prefs.wordMode = btn.getAttribute('data-word-mode') === 'or' ? 'or' : 'and';
        writeSearchPrefs(prefs);
        syncUi(root.querySelector('input[type="search"]')?.value || '');
        onChange?.();
      });
    });

    fuzzyBtn?.addEventListener('click', () => {
      const prefs = readSearchPrefs();
      prefs.fuzzy = !prefs.fuzzy;
      writeSearchPrefs(prefs);
      syncUi(root.querySelector('input[type="search"]')?.value || '');
      onChange?.();
    });

    syncUi('');
    return syncUi;
  };

  const formatSearchModeBits = (query, prefs) => {
    const bits = [];
    const words = Fuzzy?.getSearchWords
      ? Fuzzy.getSearchWords(query)
      : String(query || '')
          .toLowerCase()
          .split(/\s+/)
          .filter(Boolean);
    if (words.length > 1) bits.push(String(prefs.wordMode || 'and').toUpperCase());
    if (prefs.fuzzy) bits.push('Fuzzy');
    if (words.length) bits.push(`“${String(query).trim()}”`);
    return bits;
  };
  const copyTextToClipboard = async (text) => {
    const value = String(text || '');
    if (!value) return false;
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(value);
        return true;
      }
    } catch {
      /* fall through */
    }
    try {
      const area = document.createElement('textarea');
      area.value = value;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.left = '-9999px';
      document.body.appendChild(area);
      area.select();
      const ok = document.execCommand('copy');
      document.body.removeChild(area);
      return ok;
    } catch {
      return false;
    }
  };

  const bindCopyLinkButtons = (root) => {
    root?.querySelectorAll('.sp-copy-link-btn[data-copy-url]').forEach((btn) => {
      btn.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        const url = btn.getAttribute('data-copy-url') || '';
        if (!url) return;
        const ok = await copyTextToClipboard(url);
        const label = btn.getAttribute('data-label') || '📋';
        btn.textContent = ok ? '✓' : '!';
        btn.classList.toggle('is-copied', ok);
        btn.classList.toggle('is-copy-failed', !ok);
        window.setTimeout(() => {
          btn.textContent = label;
          btn.classList.remove('is-copied', 'is-copy-failed');
        }, 1200);
      });
    });
  };

  const nameCellHtml = (item, depth = 0, treeToggle = '') => {
    const name = String(item.name || '');
    const url = String(item.web_url || '');
    const path = String(item.relative_path || '');
    const meta = resolveMeta(item);
    const pathHtml =
      path && path !== name && depth === 0
        ? `<span class="sharepoint-link-path">${escapeHtml(path)}</span>`
        : '';
    const nameInner = `
      <span class="sp-file-icon sp-file-icon--${escapeHtml(meta.tone)}" aria-hidden="true">${meta.emoji}</span>
      <span class="sp-file-copy">
        <span class="sp-file-name">${escapeHtml(name)}</span>
        ${pathHtml}
      </span>`;
    const link = url
      ? `<a class="sp-file-link" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${nameInner}</a>`
      : `<span class="sp-file-link sp-file-link--static">${nameInner}</span>`;
    const copyBtn = url
      ? `<button type="button" class="sp-copy-link-btn" data-copy-url="${escapeHtml(url)}" data-label="📋" title="Copy SharePoint link" aria-label="Copy link for ${escapeHtml(name)}">📋</button>`
      : '';
    return `<div class="sp-tree-cell" style="--sp-depth:${depth}">${treeToggle}${link}${copyBtn}</div>`;
  };

  const typeBadgeHtml = (item) => {
    const meta = resolveMeta(item);
    const isFolder = isFolderItem(item);
    return `<span class="sp-type-badge sp-type-badge--${escapeHtml(meta.tone)}">${escapeHtml(
      isFolder ? '📁 Folder' : meta.label
    )}</span>`;
  };

  const personCellHtml = (name, emoji) => {
    const value = String(name || '').trim();
    return value ? `<span class="sp-person-cell">${emoji} ${escapeHtml(value)}</span>` : '—';
  };

  const resolveProjectMeta = (project) => {
    const name = String(project?.project_name || '');
    const ext = fileExtension(name);
    if (ext) {
      return FILE_META[ext] || { emoji: '📄', label: ext.toUpperCase(), tone: 'file' };
    }
    return FILE_META.folder;
  };

  const itemCountsHtml = (project) => {
    const folders = Number(project?.folder_count || 0);
    const files = Number(project?.file_count || 0);
    const parts = [];
    if (folders > 0) {
      parts.push(`<span class="sp-type-badge sp-type-badge--folder">📁 ${folders}</span>`);
    }
    if (files > 0) {
      parts.push(`<span class="sp-type-badge sp-type-badge--file">📄 ${files}</span>`);
    }
    if (!parts.length) {
      parts.push('<span class="sp-type-badge sp-type-badge--folder">📁 0</span>');
    }
    return `<span class="sharepoint-item-counts" title="${folders} folders · ${files} files">${parts.join('')}</span>`;
  };

  const projectNameCellHtml = (project, sourceKey, sourceTitle, extraHtml = '', openQuery = '') => {
    const name = String(project?.project_name || '');
    const folderUrl = String(project?.folder_url || '');
    const meta = resolveProjectMeta(project);
    const typeLabel = meta.tone === 'folder' ? '📁 Folder' : meta.label;
    const copyBtn = folderUrl
      ? `<button type="button" class="sp-copy-link-btn" data-copy-url="${escapeHtml(folderUrl)}" data-label="📋" title="Copy SharePoint link" aria-label="Copy link for ${escapeHtml(name)}">📋</button>`
      : '';
    return `<div class="sp-project-cell">
      <div class="sp-tree-cell">
        <button type="button" class="sharepoint-project-open sp-file-link" data-project-name="${escapeHtml(name)}" data-source-key="${escapeHtml(sourceKey)}" data-open-query="${escapeHtml(openQuery)}">
          <span class="sp-file-icon sp-file-icon--${escapeHtml(meta.tone)}" aria-hidden="true">${meta.emoji}</span>
          <span class="sp-file-copy">
            <span class="sp-file-name">${escapeHtml(name)}</span>
          </span>
        </button>
        ${copyBtn}
      </div>
      <div class="sp-project-meta-line">
        <span class="sp-type-badge sp-type-badge--${escapeHtml(meta.tone)}">${escapeHtml(typeLabel)}</span>
        <span class="sp-catalog-badge">${escapeHtml(sourceTitle)}</span>
      </div>
      ${extraHtml}
    </div>`;
  };

  const dialogItemCellsHtml = (item, depth, toggle) => {
    const person = String(item?.person || '').trim();
    const modifiedBy = String(item?.modified_by || '').trim();
    return `<td>${nameCellHtml(item, depth, toggle)}</td>
      <td>${typeBadgeHtml(item)}</td>
      <td class="sp-meta-cell sp-size-cell">${escapeHtml(formatSize(item?.size_bytes))}</td>
      <td class="sp-meta-cell">${escapeHtml(formatModified(item?.last_modified))}</td>
      <td class="sp-meta-cell">${escapeHtml(formatModified(item?.date_created))}</td>
      <td class="sp-meta-cell">${personCellHtml(modifiedBy, '👤')}</td>
      <td class="sp-meta-cell">${personCellHtml(person, '🙋')}</td>`;
  };

  /* ---- Project detail dialog ---- */
  const initDialog = () => {
    const dialog = document.getElementById('sharepoint-project-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return null;
    bindWorkspaceDialog(dialog);

    const titleEl = document.getElementById('sharepoint-project-dialog-title');
    const subEl = document.getElementById('sharepoint-project-dialog-sub');
    const statsEl = document.getElementById('sharepoint-project-dialog-stats');
    const actionsEl = document.getElementById('sharepoint-project-dialog-actions');
    const rowsEl = document.getElementById('sharepoint-project-dialog-rows');
    const closeBtn = document.getElementById('sharepoint-project-dialog-close');
    const searchWrap = document.getElementById('sharepoint-project-dialog-search-wrap');
    const searchInput = document.getElementById('sharepoint-project-dialog-search');
    const searchClear = document.getElementById('sharepoint-project-dialog-search-clear');
    const searchMeta = document.getElementById('sharepoint-project-dialog-search-meta');
    const kindSelect = document.getElementById('sharepoint-project-dialog-kind');
    const extSelect = document.getElementById('sharepoint-project-dialog-ext');

    let allItems = [];
    let layout = 'tree';
    const expanded = new Set();
    const selectedExts = new Set();
    let syncSearchModes = () => readSearchPrefs();

    const clearActivityStats = () => {
      if (!statsEl) return;
      statsEl.hidden = true;
      statsEl.innerHTML = '';
    };

    const applyProjectDensity = (next) => applyDialogDensity(dialog, searchWrap, next);

    const syncLayoutButtons = () => {
      searchWrap?.querySelectorAll('.sp-view-btn[data-layout]').forEach((btn) => {
        const active = btn.getAttribute('data-layout') === layout;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      const treeActions = searchWrap?.querySelector('.sp-tree-actions');
      if (treeActions) treeActions.hidden = layout !== 'tree';
    };

    const expandAllFolders = () => {
      collectFolderPaths(allItems).forEach((path) => expanded.add(path));
      applyFilter();
    };

    const collapseAllFolders = () => {
      expanded.clear();
      applyFilter();
    };

    const syncExtSelectFromChips = () => {
      if (!extSelect) return;
      if (selectedExts.size === 1) {
        extSelect.value = [...selectedExts][0];
      } else {
        extSelect.value = '';
      }
    };

    const activeExtFilter = () => {
      if (selectedExts.size > 0) return [...selectedExts];
      const fromSelect = String(extSelect?.value || '').trim();
      return fromSelect ? [fromSelect] : [];
    };

    const renderTreeRows = (nodes, depth, acc) => {
      nodes.forEach((node) => {
        const path = node.path;
        const isFolder = isFolderItem(node.item);
        const hasKids = isFolder && node.children.length > 0;
        const isOpen = hasKids && (expanded.has(path.toLowerCase()) || String(searchInput?.value || '').trim() !== '' || selectedExts.size > 0);
        const toggle = hasKids
          ? `<button type="button" class="sp-tree-toggle" data-tree-path="${escapeHtml(path)}" aria-expanded="${isOpen ? 'true' : 'false'}">${isOpen ? '▼' : '▶'}</button>`
          : `<span class="sp-tree-toggle sp-tree-toggle--spacer" aria-hidden="true"></span>`;
        acc.push(`<tr class="sp-dialog-row${isFolder ? ' sp-dialog-row--folder' : ''}${node.selfMatch === false && hasKids ? ' sp-tree-ancestor' : ''}">
          ${dialogItemCellsHtml(node.item, depth, toggle)}
        </tr>`);
        if (isOpen) renderTreeRows(node.children, depth + 1, acc);
      });
    };

    const applyFilter = () => {
      const query = searchInput?.value || '';
      const kind = kindSelect?.value || 'all';
      const ext = activeExtFilter();
      const prefs = syncSearchModes(query);
      syncLayoutButtons();
      syncExtChips(searchWrap, allItems, selectedExts);

      let shown = 0;
      if (layout === 'tree') {
        const tree = filterTree(buildTreeNodes(allItems), kind, ext, query, prefs);
        const rows = [];
        renderTreeRows(tree, 0, rows);
        shown = rows.length;
        rowsEl.innerHTML =
          rows.length > 0
            ? rows.join('')
            : `<tr><td colspan="7" class="sharepoint-dialog-empty">${
                allItems.length === 0
                  ? '🗂️ No files or folders found for this project.'
                  : 'No matches for this view / filter. Try OR mode or Fuzzy.'
              }</td></tr>`;
        rowsEl.querySelectorAll('.sp-tree-toggle[data-tree-path]').forEach((btn) => {
          btn.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const path = (btn.getAttribute('data-tree-path') || '').toLowerCase();
            if (expanded.has(path)) expanded.delete(path);
            else expanded.add(path);
            applyFilter();
          });
        });
      } else {
        const filtered = flattenFiltered(allItems, kind, ext, query, prefs);
        shown = filtered.length;
        if (filtered.length === 0) {
          rowsEl.innerHTML = `<tr><td colspan="7" class="sharepoint-dialog-empty">${
            allItems.length === 0
              ? '🗂️ No files or folders found for this project.'
              : 'No matches for this view / filter. Try OR mode or Fuzzy.'
          }</td></tr>`;
        } else {
          rowsEl.innerHTML = filtered
            .map((item) => {
              return `<tr class="sp-dialog-row${isFolderItem(item) ? ' sp-dialog-row--folder' : ''}">
                ${dialogItemCellsHtml(item, 0, '')}
              </tr>`;
            })
            .join('');
        }
      }

      if (searchMeta) {
        const bits = [`${shown} shown`, `of ${allItems.length}`];
        if (kind !== 'all') bits.push(kind === 'files' ? 'files only' : 'folders only');
        if (ext.length) bits.push(ext.map((value) => `.${value}`).join(' + '));
        if (layout === 'tree') bits.push('tree');
        bits.push(...formatSearchModeBits(query, prefs));
        searchMeta.textContent = bits.join(' · ');
      }
      if (searchClear) {
        searchClear.hidden = String(query || '').trim() === '' && selectedExts.size === 0;
      }
      bindCopyLinkButtons(rowsEl);
    };

    syncSearchModes = bindDialogSearchModes(searchWrap, applyFilter);
    const scheduleDialogFilter = debouncePaint(applyFilter, 60);

    searchInput?.addEventListener('input', () => scheduleDialogFilter());
    kindSelect?.addEventListener('change', applyFilter);
    extSelect?.addEventListener('change', () => {
      selectedExts.clear();
      const value = String(extSelect?.value || '')
        .toLowerCase()
        .replace(/^\./, '');
      if (value) selectedExts.add(value);
      if (value && kindSelect && kindSelect.value === 'folders') kindSelect.value = 'files';
      applyFilter();
    });
    searchClear?.addEventListener('click', () => {
      if (searchInput) searchInput.value = '';
      selectedExts.clear();
      syncExtSelectFromChips();
      scheduleDialogFilter.flush();
      searchInput?.focus();
    });
    searchWrap?.querySelectorAll('.sp-view-btn[data-layout]').forEach((btn) => {
      btn.addEventListener('click', () => {
        layout = btn.getAttribute('data-layout') === 'flat' ? 'flat' : 'tree';
        applyFilter();
      });
    });
    searchWrap?.querySelectorAll('.sp-view-btn[data-density]').forEach((btn) => {
      btn.addEventListener('click', () => {
        applyProjectDensity(btn.getAttribute('data-density') || 'compact');
      });
    });
    applyProjectDensity(readDialogDensity());
    searchWrap?.querySelectorAll('.sp-tree-action-btn[data-tree-action]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (layout !== 'tree') return;
        if (btn.getAttribute('data-tree-action') === 'expand') expandAllFolders();
        else collapseAllFolders();
      });
    });
    searchWrap?.querySelectorAll('.sp-dialog-chip[data-ext]').forEach((chip) => {
      chip.addEventListener('click', () => {
        const ext = String(chip.getAttribute('data-ext') || '')
          .toLowerCase()
          .replace(/^\./, '');
        if (!ext) return;
        if (selectedExts.has(ext)) selectedExts.delete(ext);
        else selectedExts.add(ext);
        if (selectedExts.size > 0 && kindSelect && kindSelect.value === 'folders') {
          kindSelect.value = 'files';
        }
        syncExtSelectFromChips();
        applyFilter();
      });
    });

    const openProject = async (projectName, sourceKey = '', initialQuery = '') => {
      const name = String(projectName || '').trim();
      if (!name) return;

      titleEl.textContent = name;
      subEl.innerHTML = '⏳ Loading SharePoint details…';
      clearActivityStats();
      actionsEl.innerHTML = '';
      allItems = [];
      expanded.clear();
      selectedExts.clear();
      layout = 'tree';
      if (searchInput) searchInput.value = String(initialQuery || '').trim();
      if (kindSelect) kindSelect.value = 'all';
      if (extSelect) extSelect.value = '';
      if (searchWrap) searchWrap.hidden = true;
      rowsEl.innerHTML = '<tr><td colspan="7" class="sharepoint-dialog-empty">⏳ Loading…</td></tr>';
      dialog.__spPrepareWorkspace?.();
      dialog.showModal();
      dialog.__spPrepareWorkspace?.();

      try {
        const project = await fetchProjectDetail(name, sourceKey);
        allItems = Array.isArray(project.items) ? project.items : [];
        // Expand top-level folders by default for easier browsing.
        buildTreeNodes(allItems).forEach((node) => {
          if (isFolderItem(node.item)) expanded.add(node.path.toLowerCase());
        });
        const folders = allItems.filter((item) => isFolderItem(item)).length;
        const files = allItems.length - folders;
        const catalogLabel = String(project.source_title || '').trim();
        subEl.innerHTML = `${
          catalogLabel ? `<span class="sp-catalog-badge">${escapeHtml(catalogLabel)}</span> · ` : ''
        }📦 <strong>${allItems.length}</strong> item${allItems.length === 1 ? '' : 's'} · 📁 <strong>${folders}</strong> folder${folders === 1 ? '' : 's'} · 📄 <strong>${files}</strong> file${files === 1 ? '' : 's'}`;
        renderProjectActivityStats(
          statsEl,
          computeProjectActivityStats(allItems, project.project_name || name)
        );

        if (project.folder_url) {
          actionsEl.innerHTML = `<div class="sp-dialog-folder-actions">
            <a class="button button-primary btn-accent-violet-solid sp-open-folder-btn" href="${escapeHtml(project.folder_url)}" target="_blank" rel="noopener noreferrer" title="Open project folder in SharePoint">🔗 Open</a>
            <button type="button" class="button ghost-light sp-copy-link-btn sp-project-copy-btn" data-copy-url="${escapeHtml(project.folder_url)}" data-label="📋" title="Copy folder link" aria-label="Copy folder link">📋</button>
          </div>`;
          bindCopyLinkButtons(actionsEl);
        }

        if (searchWrap) searchWrap.hidden = false;
        applyFilter();
        window.setTimeout(() => searchInput?.focus(), 50);
      } catch (error) {
        subEl.textContent = '';
        clearActivityStats();
        if (searchWrap) searchWrap.hidden = true;
        rowsEl.innerHTML = `<tr><td colspan="7" class="sharepoint-dialog-empty">${escapeHtml(error.message || 'Failed to load project.')}</td></tr>`;
      }
    };

    closeBtn?.addEventListener('click', () => dialog.close());

    return openProject;
  };

  /* ---- Side-by-side compare dialog (2 or 3 folders) ---- */
  const initCompareDialog = () => {
    const dialog = document.getElementById('sharepoint-compare-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return null;
    bindWorkspaceDialog(dialog);

    const titleEl = document.getElementById('sharepoint-compare-dialog-title');
    const subEl = document.getElementById('sharepoint-compare-dialog-sub');
    const legendEl = document.getElementById('sharepoint-compare-legend');
    const closeBtn = document.getElementById('sharepoint-compare-dialog-close');
    const panelsEl = document.getElementById('sharepoint-compare-panels');
    const searchWrap = document.getElementById('sharepoint-compare-search-wrap');
    const kindSelect = document.getElementById('sharepoint-compare-kind');
    const uniqueOnlyEl = document.getElementById('sharepoint-compare-unique-only');

    const SIDE_IDS = ['left', 'mid', 'right'];
    const SIDE_LABELS = { left: 'left', mid: 'middle', right: 'right' };

    const sideEls = {
      left: {
        panel: document.querySelector('.sharepoint-compare-panel[data-side="left"]'),
        title: document.getElementById('sharepoint-compare-left-title'),
        sub: document.getElementById('sharepoint-compare-left-sub'),
        actions: document.getElementById('sharepoint-compare-left-actions'),
        rows: document.getElementById('sharepoint-compare-left-rows'),
        filters: document.querySelector('.sharepoint-compare-panel-filters[data-side="left"]'),
        searchInput: document.querySelector('.sp-compare-panel-search[data-side="left"]'),
        clearBtn: document.querySelector('.sp-compare-panel-clear[data-side="left"]'),
        meta: document.querySelector('.sp-compare-panel-meta[data-side="left"]'),
        expanded: new Set(),
      },
      mid: {
        panel: document.querySelector('.sharepoint-compare-panel[data-side="mid"]'),
        title: document.getElementById('sharepoint-compare-mid-title'),
        sub: document.getElementById('sharepoint-compare-mid-sub'),
        actions: document.getElementById('sharepoint-compare-mid-actions'),
        rows: document.getElementById('sharepoint-compare-mid-rows'),
        filters: document.querySelector('.sharepoint-compare-panel-filters[data-side="mid"]'),
        searchInput: document.querySelector('.sp-compare-panel-search[data-side="mid"]'),
        clearBtn: document.querySelector('.sp-compare-panel-clear[data-side="mid"]'),
        meta: document.querySelector('.sp-compare-panel-meta[data-side="mid"]'),
        expanded: new Set(),
      },
      right: {
        panel: document.querySelector('.sharepoint-compare-panel[data-side="right"]'),
        title: document.getElementById('sharepoint-compare-right-title'),
        sub: document.getElementById('sharepoint-compare-right-sub'),
        actions: document.getElementById('sharepoint-compare-right-actions'),
        rows: document.getElementById('sharepoint-compare-right-rows'),
        filters: document.querySelector('.sharepoint-compare-panel-filters[data-side="right"]'),
        searchInput: document.querySelector('.sp-compare-panel-search[data-side="right"]'),
        clearBtn: document.querySelector('.sp-compare-panel-clear[data-side="right"]'),
        meta: document.querySelector('.sp-compare-panel-meta[data-side="right"]'),
        expanded: new Set(),
      },
    };

    /** @type {string[]} */
    let activeSides = ['left', 'right'];
    /** @type {Record<string, object|null>} */
    let projectsBySide = { left: null, mid: null, right: null };
    /** @type {Record<string, array>} */
    let itemsBySide = { left: [], mid: [], right: [] };
    /** @type {Record<string, Set<string>>} */
    let keysBySide = { left: new Set(), mid: new Set(), right: new Set() };
    /** @type {Record<string, { query: string, exts: Set<string> }>} */
    let filtersBySide = {
      left: { query: '', exts: new Set() },
      mid: { query: '', exts: new Set() },
      right: { query: '', exts: new Set() },
    };
    let layout = 'tree';
    let density = 'compact';
    let syncSearchModes = () => readSearchPrefs();

    const emptySideFilter = () => ({ query: '', exts: new Set() });

    const resetSideFilter = (side) => {
      filtersBySide[side] = emptySideFilter();
      if (sideEls[side].searchInput) sideEls[side].searchInput.value = '';
      if (sideEls[side].clearBtn) sideEls[side].clearBtn.hidden = true;
      if (sideEls[side].meta) sideEls[side].meta.textContent = '';
    };

    const sideExtFilter = (side) => [...(filtersBySide[side]?.exts || [])];

    const syncLayoutButtons = () => {
      searchWrap?.querySelectorAll('.sp-view-btn[data-layout]').forEach((btn) => {
        const active = btn.getAttribute('data-layout') === layout;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      const treeActions = searchWrap?.querySelector('.sp-tree-actions');
      if (treeActions) treeActions.hidden = layout !== 'tree';
    };

    const applyCompareDensity = (next) => {
      density = applyDialogDensity(dialog, searchWrap, next);
    };

    const expandAllFolders = () => {
      activeSides.forEach((side) => {
        collectFolderPaths(itemsBySide[side]).forEach((path) => sideEls[side].expanded.add(path));
      });
      applyCompareFilter();
    };

    const collapseAllFolders = () => {
      SIDE_IDS.forEach((side) => sideEls[side].expanded.clear());
      applyCompareFilter();
    };

    const otherKeysUnion = (side) => {
      const set = new Set();
      activeSides.forEach((other) => {
        if (other === side) return;
        keysBySide[other].forEach((key) => set.add(key));
      });
      return set;
    };

    const classifyDiff = (key, side) => {
      const presentCount = activeSides.filter((s) => keysBySide[s].has(key)).length;
      if (presentCount === activeSides.length) return 'all';
      if (presentCount === 1) return `only-${side}`;
      return 'shared';
    };

    const diffLabelFor = (diff, side) => {
      if (diff === 'all') return activeSides.length === 2 ? 'In both' : 'In all';
      if (diff === 'shared') return 'Shared';
      if (diff.startsWith('only-')) return `Only ${SIDE_LABELS[side] || side}`;
      return diff;
    };

    const pillClassFor = (diff) => {
      if (diff === 'all') return 'all';
      if (diff === 'shared') return 'shared';
      if (diff === 'only-left') return 'left';
      if (diff === 'only-mid') return 'mid';
      if (diff === 'only-right') return 'right';
      return 'shared';
    };

    const renderCompareTree = (nodes, side, depth, acc, filterState) => {
      const expanded = sideEls[side].expanded;
      const searching =
        String(filterState.query || '').trim() !== '' || (filterState.exts?.size || 0) > 0;
      nodes.forEach((node) => {
        const key = itemKey(node.item);
        const diff = classifyDiff(key, side);
        const isFolder = isFolderItem(node.item);
        const hasKids = isFolder && node.children.length > 0;
        const isOpen = hasKids && (expanded.has(node.path.toLowerCase()) || searching);
        const toggle = hasKids
          ? `<button type="button" class="sp-tree-toggle" data-side="${side}" data-tree-path="${escapeHtml(node.path)}" aria-expanded="${isOpen ? 'true' : 'false'}">${isOpen ? '▼' : '▶'}</button>`
          : `<span class="sp-tree-toggle sp-tree-toggle--spacer" aria-hidden="true"></span>`;
        const tone = pillClassFor(diff);
        acc.push({
          html: `<tr class="sp-dialog-row sp-compare-row sp-compare-row--${tone}${isFolder ? ' sp-dialog-row--folder' : ''}">
            <td>${nameCellHtml(node.item, depth, toggle)}</td>
            <td>${typeBadgeHtml(node.item)}</td>
            <td><span class="sp-diff-pill sp-diff-pill--${tone}">${diffLabelFor(diff, side)}</span></td>
          </tr>`,
          shared: diff === 'all' || diff === 'shared' ? 1 : 0,
          only: diff.startsWith('only-') ? 1 : 0,
        });
        if (isOpen) renderCompareTree(node.children, side, depth + 1, acc, filterState);
      });
    };

    const renderSide = (side, kind, uniqueOnly, prefs) => {
      const rowsEl = sideEls[side].rows;
      const items = itemsBySide[side] || [];
      const others = otherKeysUnion(side);
      const filterState = filtersBySide[side] || emptySideFilter();
      const query = filterState.query || '';
      const ext = [...(filterState.exts || [])];
      let shared = 0;
      let only = 0;
      let shown = 0;

      const baseItems = uniqueOnly
        ? items.filter((item) => !others.has(itemKey(item)))
        : items;

      if (layout === 'tree') {
        const tree = filterTree(buildTreeNodes(baseItems), kind, ext, query, prefs);
        const acc = [];
        renderCompareTree(tree, side, 0, acc, filterState);
        shown = acc.length;
        acc.forEach((row) => {
          shared += row.shared;
          only += row.only;
        });
        rowsEl.innerHTML =
          acc.length > 0
            ? acc.map((row) => row.html).join('')
            : `<tr><td colspan="3" class="sharepoint-dialog-empty">${
                items.length === 0
                  ? 'No files or folders.'
                  : 'No matches for this panel filter. Try OR mode or Fuzzy.'
              }</td></tr>`;
        rowsEl.querySelectorAll('.sp-tree-toggle[data-tree-path]').forEach((btn) => {
          btn.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const path = (btn.getAttribute('data-tree-path') || '').toLowerCase();
            const set = sideEls[side].expanded;
            if (set.has(path)) set.delete(path);
            else set.add(path);
            applyCompareFilter();
          });
        });
      } else {
        const filtered = flattenFiltered(baseItems, kind, ext, query, prefs);
        shown = filtered.length;
        if (filtered.length === 0) {
          rowsEl.innerHTML = `<tr><td colspan="3" class="sharepoint-dialog-empty">${
            items.length === 0
              ? 'No files or folders.'
              : 'No matches for this panel filter. Try OR mode or Fuzzy.'
          }</td></tr>`;
        } else {
          rowsEl.innerHTML = filtered
            .map((item) => {
              const key = itemKey(item);
              const diff = classifyDiff(key, side);
              const tone = pillClassFor(diff);
              if (diff === 'all' || diff === 'shared') shared += 1;
              else only += 1;
              return `<tr class="sp-dialog-row sp-compare-row sp-compare-row--${tone}${isFolderItem(item) ? ' sp-dialog-row--folder' : ''}">
                <td>${nameCellHtml(item, 0, '')}</td>
                <td>${typeBadgeHtml(item)}</td>
                <td><span class="sp-diff-pill sp-diff-pill--${tone}">${diffLabelFor(diff, side)}</span></td>
              </tr>`;
            })
            .join('');
        }
      }

      bindCopyLinkButtons(rowsEl);
      return { shared, only, shown, query, ext };
    };

    const fillSide = (side, project, stats) => {
      const els = sideEls[side];
      const catalog = String(project.source_title || project.source_key || '').trim();
      const items = Array.isArray(project.items) ? project.items : [];
      els.title.textContent = String(project.project_name || 'Project');
      els.sub.innerHTML = `${
        catalog ? `<span class="sp-catalog-badge">${escapeHtml(catalog)}</span> · ` : ''
      }${items.length} item${items.length === 1 ? '' : 's'} · showing ${stats.shown}`;
      if (project.folder_url) {
        els.actions.innerHTML = `<div class="sp-dialog-folder-actions sp-compare-folder-actions">
          <a class="button button-primary btn-accent-violet-solid sp-compare-open-btn" href="${escapeHtml(project.folder_url)}" target="_blank" rel="noopener noreferrer">🔗 Open</a>
          <button type="button" class="button ghost-light sp-copy-link-btn sp-compare-copy-btn" data-copy-url="${escapeHtml(project.folder_url)}" data-label="📋" title="Copy folder link" aria-label="Copy folder link">📋</button>
        </div>`;
        bindCopyLinkButtons(els.actions);
      } else {
        els.actions.innerHTML = '';
      }

      syncExtChips(els.filters, items, filtersBySide[side].exts);
      if (els.clearBtn) {
        els.clearBtn.hidden =
          String(filtersBySide[side].query || '').trim() === '' && filtersBySide[side].exts.size === 0;
      }
      if (els.meta) {
        const bits = [`${stats.shown} shown`, `of ${items.length}`];
        if (stats.ext?.length) bits.push(stats.ext.map((value) => `.${value}`).join(' + '));
        bits.push(...formatSearchModeBits(stats.query || '', readSearchPrefs()));
        els.meta.textContent = bits.join(' · ');
      }
    };

    const syncPanelVisibility = () => {
      SIDE_IDS.forEach((side) => {
        const active = activeSides.includes(side);
        if (sideEls[side].panel) sideEls[side].panel.hidden = !active;
      });
      if (panelsEl) panelsEl.setAttribute('data-panel-count', String(activeSides.length));
      if (legendEl) {
        legendEl.querySelector('.sp-diff-pill--mid')?.toggleAttribute('hidden', !activeSides.includes('mid'));
        legendEl.querySelector('.sp-diff-pill--shared')?.toggleAttribute(
          'hidden',
          activeSides.length < 3
        );
        const allPill = legendEl.querySelector('.sp-diff-pill--all');
        if (allPill) allPill.textContent = activeSides.length === 2 ? 'In both' : 'In all';
      }
      syncPanelTools();
    };

    const syncPanelTools = () => {
      const canClose = activeSides.length > 2;
      activeSides.forEach((side, index) => {
        const tools = sideEls[side].panel?.querySelector('.sharepoint-compare-panel-tools');
        if (!tools) return;
        const prevBtn = tools.querySelector('[data-swap="prev"]');
        const nextBtn = tools.querySelector('[data-swap="next"]');
        const closeBtn = tools.querySelector('.sp-compare-close-btn');
        if (prevBtn) {
          prevBtn.disabled = index === 0;
          prevBtn.hidden = activeSides.length < 2;
        }
        if (nextBtn) {
          nextBtn.disabled = index >= activeSides.length - 1;
          nextBtn.hidden = activeSides.length < 2;
        }
        if (closeBtn) {
          closeBtn.hidden = !canClose;
          closeBtn.disabled = !canClose;
        }
      });
    };

    const clearSideData = (side) => {
      projectsBySide[side] = null;
      itemsBySide[side] = [];
      keysBySide[side] = new Set();
      sideEls[side].expanded.clear();
      resetSideFilter(side);
      sideEls[side].actions.innerHTML = '';
      sideEls[side].rows.innerHTML =
        '<tr><td colspan="3" class="sharepoint-dialog-empty">Select folders to compare.</td></tr>';
      sideEls[side].sub.textContent = '';
    };

    const snapshotSide = (side) => ({
      project: projectsBySide[side],
      items: itemsBySide[side],
      keys: new Set(keysBySide[side]),
      expanded: new Set(sideEls[side].expanded),
      filter: {
        query: String(filtersBySide[side]?.query || ''),
        exts: new Set(filtersBySide[side]?.exts || []),
      },
    });

    const restoreSide = (side, snap) => {
      projectsBySide[side] = snap.project;
      itemsBySide[side] = snap.items || [];
      keysBySide[side] = snap.keys instanceof Set ? new Set(snap.keys) : new Set();
      sideEls[side].expanded.clear();
      const expanded = snap.expanded instanceof Set ? snap.expanded : new Set(snap.expanded || []);
      expanded.forEach((path) => sideEls[side].expanded.add(path));
      const filter = snap.filter || emptySideFilter();
      filtersBySide[side] = {
        query: String(filter.query || ''),
        exts: filter.exts instanceof Set ? new Set(filter.exts) : new Set(filter.exts || []),
      };
      if (sideEls[side].searchInput) {
        sideEls[side].searchInput.value = filtersBySide[side].query;
      }
    };

    const refreshCompareSummary = () => {
      if (activeSides.some((side) => !projectsBySide[side])) return;
      const names = activeSides.map((side) => String(projectsBySide[side].project_name || ''));
      const uniqueNames = [...new Set(names.filter(Boolean))];
      titleEl.textContent =
        uniqueNames.length === 1 ? uniqueNames[0] : uniqueNames.join(' · ') || 'Compare';

      const allIn = itemsBySide[activeSides[0]].filter((item) => {
        const key = itemKey(item);
        return activeSides.every((side) => keysBySide[side].has(key));
      }).length;
      const onlyCounts = activeSides.map((side) => {
        const others = otherKeysUnion(side);
        return itemsBySide[side].filter((item) => !others.has(itemKey(item))).length;
      });
      subEl.innerHTML = `<strong>${allIn}</strong> in ${
        activeSides.length === 2 ? 'both' : 'all'
      } · ${activeSides
        .map((side, index) => `<strong>${onlyCounts[index]}</strong> only ${SIDE_LABELS[side]}`)
        .join(' · ')}`;
    };

    const compactActiveSides = (orderedSnaps) => {
      SIDE_IDS.forEach((side) => clearSideData(side));
      activeSides = slotSidesForCount(orderedSnaps.length);
      orderedSnaps.forEach((snap, index) => {
        restoreSide(activeSides[index], snap);
      });
      syncPanelVisibility();
      refreshCompareSummary();
      applyCompareFilter();
    };

    const removeCompareSide = (side) => {
      if (!activeSides.includes(side) || activeSides.length <= 2) return;
      const remaining = activeSides
        .filter((id) => id !== side)
        .map((id) => snapshotSide(id))
        .filter((snap) => snap.project);
      if (remaining.length < 2) return;
      compactActiveSides(remaining);
    };

    const swapCompareSides = (sideA, sideB) => {
      if (!activeSides.includes(sideA) || !activeSides.includes(sideB) || sideA === sideB) return;
      const snapA = snapshotSide(sideA);
      const snapB = snapshotSide(sideB);
      restoreSide(sideA, snapB);
      restoreSide(sideB, snapA);
      refreshCompareSummary();
      applyCompareFilter();
    };

    const swapWithNeighbor = (side, direction) => {
      const index = activeSides.indexOf(side);
      if (index < 0) return;
      const otherIndex = direction === 'prev' ? index - 1 : index + 1;
      if (otherIndex < 0 || otherIndex >= activeSides.length) return;
      swapCompareSides(side, activeSides[otherIndex]);
    };

    const applyCompareFilter = () => {
      if (activeSides.some((side) => !projectsBySide[side])) return;
      const kind = kindSelect?.value || 'all';
      const uniqueOnly = !!uniqueOnlyEl?.checked;
      // Sync AND/OR visibility from any active panel query that has multiple words.
      const sampleQuery = activeSides.map((side) => filtersBySide[side].query).find((q) => q.trim()) || '';
      const prefs = syncSearchModes(sampleQuery);
      syncLayoutButtons();
      syncPanelVisibility();

      const statsBySide = {};
      activeSides.forEach((side) => {
        statsBySide[side] = renderSide(side, kind, uniqueOnly, prefs);
        fillSide(side, projectsBySide[side], statsBySide[side]);
      });
    };

    syncSearchModes = bindDialogSearchModes(searchWrap, applyCompareFilter);
    const scheduleCompareFilter = debouncePaint(applyCompareFilter, 60);

    kindSelect?.addEventListener('change', applyCompareFilter);
    uniqueOnlyEl?.addEventListener('change', applyCompareFilter);
    searchWrap?.querySelectorAll('.sp-view-btn[data-layout]').forEach((btn) => {
      btn.addEventListener('click', () => {
        layout = btn.getAttribute('data-layout') === 'flat' ? 'flat' : 'tree';
        applyCompareFilter();
      });
    });
    searchWrap?.querySelectorAll('.sp-view-btn[data-density]').forEach((btn) => {
      btn.addEventListener('click', () => {
        applyCompareDensity(btn.getAttribute('data-density') || 'compact');
      });
    });
    applyCompareDensity(readDialogDensity());
    searchWrap?.querySelectorAll('.sp-tree-action-btn[data-tree-action]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (layout !== 'tree') return;
        if (btn.getAttribute('data-tree-action') === 'expand') expandAllFolders();
        else collapseAllFolders();
      });
    });

    SIDE_IDS.forEach((side) => {
      sideEls[side].searchInput?.addEventListener('input', () => {
        filtersBySide[side].query = sideEls[side].searchInput.value || '';
        scheduleCompareFilter();
      });
      sideEls[side].clearBtn?.addEventListener('click', () => {
        resetSideFilter(side);
        scheduleCompareFilter.flush();
        sideEls[side].searchInput?.focus();
      });
      sideEls[side].filters?.querySelectorAll('.sp-dialog-chip[data-ext]').forEach((chip) => {
        chip.addEventListener('click', () => {
          const ext = String(chip.getAttribute('data-ext') || '')
            .toLowerCase()
            .replace(/^\./, '');
          if (!ext) return;
          const set = filtersBySide[side].exts;
          if (set.has(ext)) set.delete(ext);
          else set.add(ext);
          if (set.size > 0 && kindSelect && kindSelect.value === 'folders') {
            kindSelect.value = 'files';
          }
          applyCompareFilter();
        });
      });
    });

    panelsEl?.addEventListener('click', (event) => {
      const closeBtn = event.target.closest('.sp-compare-close-btn');
      if (closeBtn) {
        event.preventDefault();
        removeCompareSide(closeBtn.getAttribute('data-side') || '');
        return;
      }
      const swapBtn = event.target.closest('.sp-compare-swap-btn');
      if (swapBtn) {
        event.preventDefault();
        const side = swapBtn.getAttribute('data-side') || '';
        const direction = swapBtn.getAttribute('data-swap') === 'prev' ? 'prev' : 'next';
        swapWithNeighbor(side, direction);
      }
    });

    const slotSidesForCount = (count) => {
      if (count >= 3) return ['left', 'mid', 'right'];
      return ['left', 'right'];
    };

    const openCompare = async (picks) => {
      const list = Array.isArray(picks) ? picks.filter(Boolean) : [];
      if (list.length < 2 || list.length > 3) return;

      activeSides = slotSidesForCount(list.length);
      titleEl.textContent = 'Compare folders';
      subEl.textContent = `Loading ${list.length} catalogs…`;
      legendEl.hidden = true;
      if (searchWrap) searchWrap.hidden = true;
      if (kindSelect) kindSelect.value = 'all';
      if (uniqueOnlyEl) uniqueOnlyEl.checked = false;
      layout = 'tree';

      SIDE_IDS.forEach((side) => {
        sideEls[side].expanded.clear();
        resetSideFilter(side);
        projectsBySide[side] = null;
        itemsBySide[side] = [];
        keysBySide[side] = new Set();
        sideEls[side].actions.innerHTML = '';
        sideEls[side].rows.innerHTML =
          '<tr><td colspan="3" class="sharepoint-dialog-empty">⏳ Loading…</td></tr>';
      });

      activeSides.forEach((side, index) => {
        sideEls[side].title.textContent = list[index].projectName || `Folder ${index + 1}`;
        sideEls[side].sub.textContent = 'Loading…';
      });
      syncPanelVisibility();
      dialog.__spPrepareWorkspace?.();
      dialog.showModal();
      dialog.__spPrepareWorkspace?.();

      try {
        const loaded = await Promise.all(
          list.map((pick) => fetchProjectDetail(pick.projectName, pick.sourceKey))
        );

        activeSides.forEach((side, index) => {
          const project = loaded[index];
          projectsBySide[side] = project;
          itemsBySide[side] = Array.isArray(project.items) ? project.items : [];
          keysBySide[side] = new Set(itemsBySide[side].map(itemKey));
          buildTreeNodes(itemsBySide[side]).forEach((node) => {
            if (isFolderItem(node.item)) sideEls[side].expanded.add(node.path.toLowerCase());
          });
        });

        refreshCompareSummary();
        legendEl.hidden = false;
        if (searchWrap) searchWrap.hidden = false;
        applyCompareFilter();
        window.setTimeout(() => sideEls[activeSides[0]]?.searchInput?.focus(), 50);
      } catch (error) {
        subEl.textContent = error.message || 'Compare failed.';
        if (searchWrap) searchWrap.hidden = true;
        activeSides.forEach((side) => {
          sideEls[side].rows.innerHTML = `<tr><td colspan="3" class="sharepoint-dialog-empty">${escapeHtml(
            error.message || 'Failed'
          )}</td></tr>`;
        });
      }
    };

    closeBtn?.addEventListener('click', () => dialog.close());

    return openCompare;
  };

  const openProject = initDialog();
  const openCompare = initCompareDialog();
  window.RiskRegisterSharePoint = Object.assign(window.RiskRegisterSharePoint || {}, {
    openProject,
  });

  bindListDensityToggle();

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
    bindCopyLinkButtons(tbody);
    return;
  }

  const STORAGE = {
    wordMode: 'riskregister_sp_search_word_mode',
    fuzzy: 'riskregister_sp_search_fuzzy',
    deep: 'riskregister_sp_search_deep',
    scopes: 'riskregister_sp_search_scopes',
  };

  const input = document.getElementById('sharepoint-search-input');
  const clearBtn = document.getElementById('sharepoint-search-clear');
  const controls = document.getElementById('sharepoint-search-controls');
  const wordModeGroup = document.getElementById('sharepoint-word-mode');
  const fuzzyToggle = document.getElementById('sharepoint-fuzzy-toggle');
  const deepToggle = document.getElementById('sharepoint-deep-toggle');
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
  const MAX_COMPARE = 3;
  const MIN_COMPARE = 2;

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
    const params = new URLSearchParams(window.location.search);
    const urlSources = params.get('sources');
    if (urlSources) {
      const fromUrl = urlSources
        .split(',')
        .map((key) => key.trim())
        .filter((key) => titleByKey[key]);
      if (fromUrl.length) return fromUrl;
    }

    // Opening / switching a catalog via ?source= should focus that catalog
    // instead of restoring a stale multi-catalog selection from localStorage.
    const urlSource = (params.get('source') || searchRoot.dataset.sourceKey || '').trim();
    if (urlSource && (titleByKey[urlSource] || urlSource === searchRoot.dataset.sourceKey)) {
      return [urlSource];
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
    deep: localStorage.getItem(STORAGE.deep) !== '0',
    page: 1,
    perPage: Number(searchRoot.dataset.perPage || perPageSelect?.value || 25) || 25,
    ready: false,
    loadingIndex: false,
  };

  const projectEntries = (project) => {
    if (Array.isArray(project?._entries)) return project._entries;
    const files = Array.isArray(project.files) ? project.files : [];
    const folders = Array.isArray(project.folders) ? project.folders : [];
    const out = [];
    files.forEach((item) => {
      const name = String(item?.name || '').trim();
      if (!name) return;
      out.push({
        kind: 'file',
        name,
        path: String(item?.path || name).trim() || name,
      });
    });
    folders.forEach((item) => {
      const name = String(item?.name || '').trim();
      if (!name) return;
      out.push({
        kind: 'folder',
        name,
        path: String(item?.path || name).trim() || name,
      });
    });
    if (out.length) return out;
    (project.names || []).forEach((name) => {
      const label = String(name || '').trim();
      if (!label || label.toLowerCase() === String(project.project_name || '').trim().toLowerCase()) return;
      out.push({ kind: 'file', name: label, path: label });
    });
    return out;
  };

  const prepareSearchProject = (project) => {
    const entries = projectEntries(project);
    project._entries = entries;
    const shallow = [
      project.project_name,
      project.source_title,
      project.modified_by,
      project.person,
    ]
      .map((value) => String(value || '').toLowerCase())
      .filter(Boolean);
    project._hayShallow = shallow.join('\n');
    project._hayDeep = [project._hayShallow]
      .concat(entries.map((entry) => `${entry.name}\n${entry.path}`.toLowerCase()))
      .join('\n');
    return project;
  };

  const haystackHasWords = (hay, words, mode) => {
    if (!hay) return false;
    if (mode === 'or') return words.some((word) => hay.includes(word));
    return words.every((word) => hay.includes(word));
  };

  const cheapProjectMatch = (project, words, mode, deep) => {
    const hay = (deep ? project._hayDeep : project._hayShallow) || '';
    if (!haystackHasWords(hay, words, mode)) return { matched: false, score: 0, kind: 'none' };
    const name = String(project.project_name || '').toLowerCase();
    const nameHit = mode === 'or' ? words.some((word) => name.includes(word)) : words.every((word) => name.includes(word));
    if (nameHit) {
      const exact = words.some((word) => name === word);
      return {
        matched: true,
        score: exact ? 100 : 94,
        kind: exact ? 'exact' : 'contains',
        source: 'Project',
        snippet: project.project_name,
      };
    }
    return { matched: true, score: 86, kind: 'contains', source: deep ? 'File' : 'Catalog' };
  };

  const projectFields = (project, deep = true) => {
    const fields = [
      { text: project.project_name, sourceLabel: 'Project' },
      { text: project.source_title, sourceLabel: 'Catalog' },
      { text: project.modified_by, sourceLabel: 'Modified By' },
      { text: project.person, sourceLabel: 'Created By' },
    ];
    if (!deep) return fields;
    const entries = projectEntries(project);
    if (entries.length) {
      entries.forEach((entry) => {
        fields.push({
          text: entry.name,
          sourceLabel: entry.kind === 'folder' ? 'Folder' : 'File',
          sourceName: entry.name,
        });
        if (entry.path && entry.path !== entry.name) {
          fields.push({ text: entry.path, sourceLabel: 'Path', sourceName: entry.path });
        }
      });
      return fields;
    }
    (project.names || []).forEach((name) => {
      fields.push({ text: name, sourceLabel: 'File', sourceName: name });
    });
    (project.paths || []).forEach((path) => {
      fields.push({ text: path, sourceLabel: 'Path', sourceName: path });
    });
    return fields;
  };

  const scoreProject = (project, words, mode, fuzzy, deep = true) => {
    if (!words.length) return { matched: true, score: 100, kind: 'exact' };
    if (!fuzzy) {
      const cheap = cheapProjectMatch(project, words, mode, deep);
      if (!cheap.matched) return cheap;
    }
    return Fuzzy.scoreLabeledFieldsAgainstWords(projectFields(project, deep), words, mode, fuzzy);
  };

  const collectDeepHits = (project, words, mode, fuzzy, limit = 4) => {
    if (!words.length) return { hits: [], total: 0 };
    const hits = projectEntries(project)
      .map((entry) => {
        const match = Fuzzy.scoreLabeledFieldsAgainstWords(
          [
            {
              text: entry.name,
              sourceLabel: entry.kind === 'folder' ? 'Folder' : 'File',
              sourceName: entry.name,
            },
            { text: entry.path, sourceLabel: 'Path', sourceName: entry.path },
          ],
          words,
          mode,
          fuzzy
        );
        return match.matched ? { ...entry, match } : null;
      })
      .filter(Boolean);
    hits.sort((a, b) => (b.match?.score || 0) - (a.match?.score || 0));
    return { hits: hits.slice(0, limit), total: hits.length };
  };

  const deepHitsHtml = (hitSet) => {
    const hits = hitSet?.hits || [];
    if (!hits.length) return '';
    const extra = Math.max(0, (hitSet.total || hits.length) - hits.length);
    return `<div class="sp-deep-hits">
      ${hits
        .map((hit) => {
          const icon = hit.kind === 'folder' ? '📁' : '📄';
          const path = hit.path && hit.path !== hit.name ? hit.path : '';
          return `<button type="button" class="sp-deep-hit" data-hit-query="${escapeHtml(hit.name)}" title="${escapeHtml(
            path ? `${hit.name} — ${path}` : hit.name
          )}">
            <span aria-hidden="true">${icon}</span>
            <span class="sp-deep-hit-name">${escapeHtml(hit.name)}</span>
            ${path ? `<span class="sp-deep-hit-path">${escapeHtml(path)}</span>` : ''}
          </button>`;
        })
        .join('')}
      ${extra ? `<span class="sp-deep-hit-more">+${extra} more</span>` : ''}
    </div>`;
  };

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

  const syncActiveCatalogChrome = () => {
    const key = state.scopeKeys.length === 1 ? state.scopeKeys[0] : state.sourceKey;
    const source =
      availableSources.find((src) => String(src.source_key || '') === String(key || '')) || null;
    const title =
      (source && (source.title || source.source_key)) ||
      titleByKey[key] ||
      searchRoot.dataset.sourceTitle ||
      'SharePoint catalog';

    updateHeading();

    const heroTitle = document.getElementById('sharepoint-hero-title');
    if (heroTitle && state.scopeKeys.length <= 1) {
      heroTitle.textContent = title;
    }

    const heroFolder = document.getElementById('sharepoint-hero-folder');
    if (heroFolder && source && state.scopeKeys.length <= 1) {
      heroFolder.textContent = String(source.folder_path || heroFolder.textContent || '');
    }

    const heroSite = document.getElementById('sharepoint-hero-site');
    if (heroSite && source && state.scopeKeys.length <= 1) {
      heroSite.textContent = `${String(source.site_host || '')}${String(source.site_path || '')}`;
    }

    document.querySelectorAll('.sharepoint-source-card[data-source-key], .sharepoint-source-row[data-source-key]').forEach((card) => {
      const cardKey = card.getAttribute('data-source-key') || '';
      const isActive = state.scopeKeys.length <= 1 && cardKey === String(key || '');
      card.classList.toggle('is-active', isActive);
      let badge = card.querySelector('.sharepoint-source-badge');
      const badgeHost =
        card.querySelector('.sharepoint-source-card-head') ||
        card.querySelector('.sharepoint-source-table-title');
      if (isActive) {
        if (!badge && badgeHost) {
          badge = document.createElement('span');
          badge.className = 'sharepoint-source-badge';
          badge.textContent = 'Active';
          badgeHost.appendChild(badge);
        }
      } else if (badge) {
        badge.remove();
      }
    });
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
        compareHintEl.textContent = 'Select 2–3 folders to compare side by side';
      } else if (count === 1) {
        compareHintEl.textContent = '1 selected — pick 1 or 2 more catalog folders';
      } else if (count === 2) {
        compareHintEl.textContent = '2 selected — compare now, or pick a 3rd folder';
      } else {
        compareHintEl.textContent = `${count} selected — ready to compare`;
      }
    }
    if (compareOpenBtn) {
      const ready = count >= MIN_COMPARE && count <= MAX_COMPARE;
      compareOpenBtn.disabled = !ready;
      compareOpenBtn.textContent = ready
        ? `⚖️ Compare selected (${count})`
        : `⚖️ Compare selected (${count}/${MAX_COMPARE})`;
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
    const openFromRow = (el, hitQuery = '') => {
      const name = el.getAttribute('data-project-name') || '';
      const sourceKey = el.getAttribute('data-source-key') || state.sourceKey;
      const query = String(hitQuery || '').trim();
      openProject?.(name, sourceKey, query);
    };
    tbody.querySelectorAll('.sharepoint-project-open').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openFromRow(btn, btn.getAttribute('data-open-query') || '');
      });
    });
    tbody.querySelectorAll('.sp-deep-hit').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const row = btn.closest('.sharepoint-project-row');
        if (!row) return;
        openFromRow(row, btn.getAttribute('data-hit-query') || '');
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
        openFromRow(row, row.getAttribute('data-open-query') || '');
      });
      row.addEventListener('keydown', (event) => {
        if (event.target.closest('input, label')) return;
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openFromRow(row, row.getAttribute('data-open-query') || '');
        }
      });
    });
    bindCopyLinkButtons(tbody);
  };

  const filteredProjects = () => {
    const words = Fuzzy.getSearchWords(state.query);
    const refineWords = Fuzzy.getSearchWords(state.refine);

    if (!words.length) {
      return state.projects.map((project) => ({ project, match: null, deepHits: { hits: [], total: 0 } }));
    }

    let results = state.projects
      .map((project) => {
        const match = state.fuzzy
          ? scoreProject(project, words, state.wordMode, true, state.deep)
          : cheapProjectMatch(project, words, state.wordMode, state.deep);
        return { project, match, deepHits: { hits: [], total: 0 } };
      })
      .filter((row) => row.match?.matched);

    if (refineWords.length) {
      results = results
        .map((row) => {
          const refineMatch = state.fuzzy
            ? scoreProject(row.project, refineWords, state.wordMode, true, state.deep)
            : cheapProjectMatch(row.project, refineWords, state.wordMode, state.deep);
          if (!refineMatch.matched) return null;
          return {
            project: row.project,
            match: Fuzzy.combineSearchScores(row.match, refineMatch),
            deepHits: row.deepHits,
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
    if (deepToggle) {
      deepToggle.classList.toggle('is-active', state.deep);
      deepToggle.setAttribute('aria-pressed', state.deep ? 'true' : 'false');
    }
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
    const fileHitCount = state.deep
      ? rows.reduce((sum, row) => {
          const words = Fuzzy.getSearchWords(state.query);
          return (
            sum +
            projectEntries(row.project).reduce((count, entry) => {
              const hay = `${entry.name}\n${entry.path}`.toLowerCase();
              return haystackHasWords(hay, words, state.wordMode) ? count + 1 : count;
            }, 0)
          );
        }, 0)
      : 0;
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
        ${state.deep ? `<span class="sp-stats-deep">Deep files on${fileHitCount ? ` · ${fileHitCount} nested hit${fileHitCount === 1 ? '' : 's'}` : ''}</span>` : '<span class="sp-stats-deep">Folder names only</span>'}
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
    if (searching && state.deep) html += ' · <span class="sp-live-pill sp-live-pill--deep">📂 Deep files</span>';
    else if (searching) html += ' · folder names only';
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
    if (searching) {
      const words = Fuzzy.getSearchWords(state.query);
      pageRows.forEach((row) => {
        row.match = scoreProject(row.project, words, state.wordMode, state.fuzzy, state.deep);
        if (state.deep) {
          row.deepHits = collectDeepHits(row.project, words, state.wordMode, state.fuzzy);
        }
      });
    }

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
              ? `No projects matched <strong>${escapeHtml(state.query.trim())}</strong> in the selected catalog${state.scopeKeys.length === 1 ? '' : 's'}. ${state.deep ? 'Try Fuzzy, OR mode, or another file or folder name.' : 'Turn on Deep files to search nested files, or try Fuzzy / OR mode.'}`
              : 'No projects to show.'
      }</td></tr>`;
      syncCompareBar();
      return;
    }

    tbody.innerHTML = pageRows
      .map(({ project, match, deepHits }) => {
        const name = String(project.project_name || '');
        const sourceKey = String(project.source_key || state.sourceKey || '');
        const sourceTitle = String(project.source_title || titleByKey[sourceKey] || sourceKey);
        const folderUrl = String(project.folder_url || '');
        const modifiedBy = String(project.modified_by || '').trim();
        const person = String(project.person || '').trim();
        const selectId = selectionKey(sourceKey, name);
        const isSelected = state.selected.has(selectId);
        const hitSet = searching && state.deep ? deepHits : null;
        const openQuery = hitSet?.total ? state.query.trim() : '';
        const extra = `${hitSet ? deepHitsHtml(hitSet) : ''}${coverageHtml(project, presence)}`;
        return `<tr class="sharepoint-project-row${isSelected ? ' is-compare-selected' : ''}${hitSet?.total ? ' has-deep-hits' : ''}" data-project-name="${escapeHtml(name)}" data-source-key="${escapeHtml(sourceKey)}" data-open-query="${escapeHtml(openQuery)}" tabindex="0">
          <td class="sharepoint-select-col" onclick="event.stopPropagation()">
            <label class="sharepoint-row-select">
              <input type="checkbox" class="sharepoint-compare-check" value="${escapeHtml(selectId)}" data-project-name="${escapeHtml(name)}" data-source-key="${escapeHtml(sourceKey)}" ${isSelected ? 'checked' : ''} aria-label="Select ${escapeHtml(name)} for compare">
            </label>
          </td>
          <td>
            ${projectNameCellHtml(project, sourceKey, sourceTitle, extra, openQuery)}
          </td>
          <td class="sp-match-cell">${searching ? scoreBadgeHtml(match) : '<span class="sp-match-placeholder">—</span>'}</td>
          <td class="sp-meta-cell">${itemCountsHtml(project)}</td>
          <td class="sp-meta-cell">${escapeHtml(formatModified(project.last_modified))}</td>
          <td class="sp-meta-cell">${personCellHtml(modifiedBy, '👤')}</td>
          <td class="sp-meta-cell">${personCellHtml(person, '🙋')}</td>
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
    const hash = window.location.hash === '#sharepoint-owner-dash' ? '#sharepoint-owner-dash' : '#sharepoint-search';
    const next = `${window.location.pathname}${qs ? `?${qs}` : ''}${hash}`;
    window.history.replaceState(null, '', next);
  };

  const applySearch = ({ resetPage = true, syncInputs = false } = {}) => {
    if (resetPage) state.page = 1;
    if (syncInputs) {
      if (input && input.value !== state.query) input.value = state.query;
      if (refineInput && refineInput.value !== state.refine) refineInput.value = state.refine;
    }
    render();
    scheduleUrlSync();
  };

  const scheduleUrlSync = debouncePaint(syncUrl, 220);

  const runTypedSearch = () => {
    if (input) state.query = input.value;
    if (refineInput) state.refine = refineInput.value;
    applySearch({ resetPage: true, syncInputs: false });
  };

  const scheduleTypedSearch = debouncePaint(runTypedSearch, 70);
  const onSearchInput = (event) => {
    if (event?.isComposing || event?.inputType === 'insertCompositionText') return;
    if (event?.target === refineInput) {
      state.refine = refineInput?.value || '';
    } else {
      state.query = input?.value || '';
    }
    updateControlsVisibility();
    if (!state.query.trim() && !state.refine.trim()) {
      scheduleTypedSearch.flush();
      return;
    }
    scheduleTypedSearch();
  };

  const loadIndex = () => {
    const keys = state.scopeKeys.length ? state.scopeKeys : state.sourceKey ? [state.sourceKey] : [];
    if (!keys.length) return;
    state.loadingIndex = true;
    state.ready = false;
    tbody.innerHTML = '<tr class="sharepoint-empty-row"><td colspan="8">⏳ Loading live search index…</td></tr>';
    syncActiveCatalogChrome();

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
        state.projects = payload.projects.map(prepareSearchProject);
        state.itemCount = Number(payload.item_count || state.itemCount);
        state.projectCount = Number(payload.project_count || state.projects.length);
        state.lastSynced = payload.last_synced_at || state.lastSynced;
        state.lastStatus = payload.last_sync_status || state.lastStatus;
        state.loadingIndex = false;
        state.ready = true;
        applySearch({ resetPage: true, syncInputs: true });
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
    // When focusing a single catalog (Open catalog / This catalog only), treat it as active.
    if (next.length === 1) {
      state.sourceKey = next[0];
      searchRoot.dataset.sourceKey = next[0];
      searchRoot.dataset.sourceTitle = titleByKey[next[0]] || next[0];
    }
    localStorage.setItem(STORAGE.scopes, JSON.stringify(state.scopeKeys));
    syncActiveCatalogChrome();
    loadIndex();
  };

  form?.addEventListener('submit', (event) => {
    event.preventDefault();
    state.query = input?.value || '';
    scheduleTypedSearch.flush();
  });

  let searchComposing = false;
  input?.addEventListener('compositionstart', () => {
    searchComposing = true;
  });
  input?.addEventListener('compositionend', () => {
    searchComposing = false;
    onSearchInput({ target: input });
  });
  input?.addEventListener('input', (event) => {
    if (searchComposing) return;
    onSearchInput(event);
  });

  clearBtn?.addEventListener('click', () => {
    state.query = '';
    state.refine = '';
    scheduleTypedSearch.cancel();
    applySearch({ resetPage: true, syncInputs: true });
    input?.focus();
  });

  refineInput?.addEventListener('input', onSearchInput);

  refineClear?.addEventListener('click', () => {
    state.refine = '';
    applySearch({ resetPage: true, syncInputs: true });
    refineInput?.focus();
  });

  fuzzyToggle?.addEventListener('click', () => {
    state.fuzzy = !state.fuzzy;
    localStorage.setItem(STORAGE.fuzzy, state.fuzzy ? '1' : '0');
    applySearch();
  });

  deepToggle?.addEventListener('click', () => {
    state.deep = !state.deep;
    localStorage.setItem(STORAGE.deep, state.deep ? '1' : '0');
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
    if (picks.length < MIN_COMPARE || picks.length > MAX_COMPARE || !openCompare) return;
    openCompare(picks);
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

  // Align active catalog chrome with URL / resolved scopes (Open catalog).
  if (state.scopeKeys.length === 1) {
    state.sourceKey = state.scopeKeys[0];
    searchRoot.dataset.sourceKey = state.scopeKeys[0];
    searchRoot.dataset.sourceTitle = titleByKey[state.scopeKeys[0]] || state.scopeKeys[0];
  }
  try {
    localStorage.setItem(STORAGE.scopes, JSON.stringify(state.scopeKeys));
  } catch {
    /* ignore */
  }

  controls.hidden = false;
  syncScopeChips();
  syncCompareBar();
  syncActiveCatalogChrome();
  loadIndex();
})();

(() => {
  const VIEW_KEY = 'ra-sp-folders-view';
  const ADMIN_KEY = 'ra-sp-admin-open';
  const ADD_KEY = 'ra-sp-add-folder-open';
  const FOLDERS_KEY = 'ra-sp-folders-open';
  const allowedViews = ['cards', 'compact', 'table'];
  const panel = document.getElementById('sharepoint-sources');
  const grid = document.getElementById('sharepoint-sources-grid');
  const tableWrap = document.getElementById('sharepoint-sources-table-wrap');
  const toggle = panel?.querySelector('.sharepoint-folders-view-toggle');

  const applyFoldersView = (view) => {
    const next = allowedViews.includes(view) ? view : 'cards';
    if (!panel) return;
    panel.setAttribute('data-folders-view', next);
    if (grid) grid.hidden = next === 'table';
    if (tableWrap) tableWrap.hidden = next !== 'table';
    toggle?.querySelectorAll('[data-folders-view]').forEach((btn) => {
      const active = btn.getAttribute('data-folders-view') === next;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    try {
      localStorage.setItem(VIEW_KEY, next);
    } catch {
      /* ignore */
    }
  };

  const savedView = (() => {
    try {
      return localStorage.getItem(VIEW_KEY) || 'cards';
    } catch {
      return 'cards';
    }
  })();
  applyFoldersView(savedView);

  toggle?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    const btn = event.target.closest('[data-folders-view]');
    if (!btn) return;
    applyFoldersView(btn.getAttribute('data-folders-view') || 'cards');
  });

  const foldersShell = document.getElementById('sharepoint-sources-shell');
  if (foldersShell) {
    try {
      const savedFolders = localStorage.getItem(FOLDERS_KEY);
      if (savedFolders === '0') foldersShell.open = false;
      else if (savedFolders === '1') foldersShell.open = true;
    } catch {
      /* ignore */
    }
    foldersShell.addEventListener('toggle', () => {
      try {
        localStorage.setItem(FOLDERS_KEY, foldersShell.open ? '1' : '0');
      } catch {
        /* ignore */
      }
    });
  }

  const adminShell = document.getElementById('sharepoint-admin-shell');
  const adminBlocks = Array.from(document.querySelectorAll('#sharepoint-admin .sharepoint-admin-block[id]'));

  const readAdminState = () => {
    try {
      const raw = JSON.parse(localStorage.getItem(ADMIN_KEY) || 'null');
      return raw && typeof raw === 'object' ? raw : null;
    } catch {
      return null;
    }
  };

  const writeAdminState = () => {
    if (!adminShell && !adminBlocks.length) return;
    const state = {
      shell: adminShell ? adminShell.open : true,
      blocks: Object.fromEntries(adminBlocks.map((el) => [el.id, el.open])),
    };
    try {
      localStorage.setItem(ADMIN_KEY, JSON.stringify(state));
    } catch {
      /* ignore */
    }
  };

  const savedAdmin = readAdminState();
  if (savedAdmin) {
    if (adminShell && typeof savedAdmin.shell === 'boolean') {
      adminShell.open = savedAdmin.shell;
    }
    if (savedAdmin.blocks && typeof savedAdmin.blocks === 'object') {
      adminBlocks.forEach((el) => {
        if (typeof savedAdmin.blocks[el.id] === 'boolean') {
          el.open = savedAdmin.blocks[el.id];
        }
      });
    }
  }

  adminShell?.addEventListener('toggle', writeAdminState);
  adminBlocks.forEach((el) => el.addEventListener('toggle', writeAdminState));

  const addShell = document.getElementById('sharepoint-add-source-shell');
  if (addShell) {
    try {
      const savedAdd = localStorage.getItem(ADD_KEY);
      if (savedAdd === '1') addShell.open = true;
      else if (savedAdd === '0') addShell.open = false;
    } catch {
      /* ignore */
    }
    addShell.addEventListener('toggle', () => {
      try {
        localStorage.setItem(ADD_KEY, addShell.open ? '1' : '0');
      } catch {
        /* ignore */
      }
    });
  }

  document.querySelectorAll('.sharepoint-feature-toggle input[type="checkbox"]').forEach((input) => {
    const syncToggle = () => {
      input.closest('.sharepoint-feature-toggle')?.classList.toggle('is-on', input.checked);
    };
    input.addEventListener('change', syncToggle);
    syncToggle();
  });
})();
