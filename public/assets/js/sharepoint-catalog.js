(() => {
  const Fuzzy = window.FuzzySearch;
  const escapeHtml = (value) =>
    String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

  const CATALOG_COLORS_KEY = 'riskregister_sp_catalog_colors';
  const CATALOG_CUSTOM_COLORS_KEY = 'riskregister_sp_catalog_custom_colors';
  const CATALOG_EXTRA_TONES = ['violet', 'sky', 'lime', 'slate'];
  const CATALOG_TONE_HEX = {
    public: '#0f766e',
    private: '#e11d48',
    dump: '#d97706',
    violet: '#7c3aed',
    sky: '#0284c7',
    lime: '#65a30d',
    slate: '#475569',
  };
  let catalogToneByKey = {};
  let catalogCustomColors = {};
  let catalogColorPopKey = '';
  let catalogColorPopAnchor = null;
  let catalogSearchState = null;

  const catalogToneFromHay = (hay) => {
    const text = String(hay || '').toLowerCase();
    if (/\bprivate\b/.test(text)) return 'private';
    if (/\bpublic\b/.test(text)) return 'public';
    if (/\b(tprm|dump|legacy)\b/.test(text)) return 'dump';
    return '';
  };

  const readSourcesJson = (el) => {
    try {
      const raw = JSON.parse(el?.dataset?.sources || '[]');
      return Array.isArray(raw) ? raw : [];
    } catch {
      return [];
    }
  };

  const buildCatalogToneMap = (sources) => {
    const map = {};
    let extra = 0;
    (Array.isArray(sources) ? sources : []).forEach((src) => {
      const key = String(src?.source_key || '').trim();
      if (!key) return;
      const given = String(src?.tone || '').trim();
      if (given) {
        map[key] = given;
        return;
      }
      map[key] =
        catalogToneFromHay(`${src?.title || ''} ${key}`) ||
        CATALOG_EXTRA_TONES[extra++ % CATALOG_EXTRA_TONES.length];
    });
    return map;
  };

  const refreshCatalogToneMap = () => {
    catalogToneByKey = {
      ...buildCatalogToneMap(readSourcesJson(document.getElementById('sharepoint-owner-dash'))),
      ...buildCatalogToneMap(readSourcesJson(document.getElementById('sharepoint-search'))),
    };
  };

  const catalogToneFor = (sourceKey, title = '') => {
    const key = String(sourceKey || '').trim();
    if (key && catalogToneByKey[key]) return catalogToneByKey[key];
    return catalogToneFromHay(`${title} ${key}`) || 'slate';
  };

  const normalizeHex = (value) => {
    const raw = String(value || '').trim();
    if (/^#[0-9a-f]{6}$/i.test(raw)) return raw.toLowerCase();
    if (/^[0-9a-f]{6}$/i.test(raw)) return `#${raw.toLowerCase()}`;
    const short = /^#([0-9a-f]{3})$/i.exec(raw);
    if (!short) return '';
    const [a, b, c] = short[1].toLowerCase().split('');
    return `#${a}${a}${b}${b}${c}${c}`;
  };

  const hexToRgb = (hex) => {
    const m = /^#([0-9a-f]{6})$/i.exec(String(hex || ''));
    if (!m) return null;
    const n = parseInt(m[1], 16);
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
  };

  const rgbToHex = ({ r, g, b }) =>
    `#${[r, g, b].map((v) => Math.max(0, Math.min(255, v)).toString(16).padStart(2, '0')).join('')}`;

  const inkFromHex = (hex) => {
    const rgb = hexToRgb(hex);
    if (!rgb) return '#334155';
    const y = (rgb.r * 299 + rgb.g * 587 + rgb.b * 114) / 1000;
    const factor = y > 160 ? 0.42 : 0.72;
    return rgbToHex({
      r: Math.round(rgb.r * factor),
      g: Math.round(rgb.g * factor),
      b: Math.round(rgb.b * factor),
    });
  };

  const contrastInk = (hex) => {
    const rgb = hexToRgb(hex);
    if (!rgb) return '#0c1524';
    const y = (rgb.r * 299 + rgb.g * 587 + rgb.b * 114) / 1000;
    return y > 160 ? '#0c1524' : '#f8fafc';
  };

  const normalizeColorEntry = (value) => {
    if (typeof value === 'string') {
      const bg = normalizeHex(value);
      return bg ? { bg } : null;
    }
    if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
    const bg = normalizeHex(value.bg);
    const text = normalizeHex(value.text);
    if (!bg && !text) return null;
    const out = {};
    if (bg) out.bg = bg;
    if (text) out.text = text;
    return out;
  };

  const cssEscapeValue = (value) => {
    const text = String(value || '');
    if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(text);
    return text.replace(/[^a-zA-Z0-9_-]/g, '\\$&');
  };

  const defaultHexForKey = (key) => CATALOG_TONE_HEX[catalogToneByKey[key] || 'slate'] || '#475569';

  const customEntryFor = (key) => catalogCustomColors[String(key || '').trim()] || null;

  const hexForSource = (key) => customEntryFor(key)?.bg || defaultHexForKey(key);

  const textForSource = (key) => {
    const custom = customEntryFor(key);
    if (custom?.text) return custom.text;
    if (custom?.bg) return contrastInk(custom.bg);
    return inkFromHex(defaultHexForKey(key));
  };

  const readCustomCatalogColors = () => {
    try {
      const raw = JSON.parse(localStorage.getItem(CATALOG_CUSTOM_COLORS_KEY) || '{}');
      if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return {};
      const out = {};
      Object.entries(raw).forEach(([key, value]) => {
        const entry = normalizeColorEntry(value);
        if (key && entry) out[String(key)] = entry;
      });
      return out;
    } catch {
      return {};
    }
  };

  const saveCustomCatalogColors = () => {
    try {
      localStorage.setItem(CATALOG_CUSTOM_COLORS_KEY, JSON.stringify(catalogCustomColors));
    } catch {
      /* ignore */
    }
  };

  const syncCatalogColorResetButtons = () => {
    const hasCustom = Object.keys(catalogCustomColors).length > 0;
    const distinct = document.documentElement.getAttribute('data-catalog-colors') === 'distinct';
    document.querySelectorAll('.sharepoint-scopes-color-reset').forEach((btn) => {
      btn.hidden = !distinct || !hasCustom;
    });
  };

  const paintCatalogColorStyles = () => {
    const keys = new Set([...Object.keys(catalogToneByKey), ...Object.keys(catalogCustomColors)]);
    document.querySelectorAll('.sharepoint-scope-chip[data-source-key], .sharepoint-scope-color-btn[data-source-key], .sharepoint-card-color-btn[data-source-key], .sharepoint-source-card[data-source-key], .sharepoint-source-row[data-source-key]').forEach((el) => {
      const key = el.getAttribute('data-source-key') || '';
      if (key) keys.add(key);
    });
    const rules = [];
    keys.forEach((key) => {
      const hex = hexForSource(key);
      const ink = textForSource(key);
      const sel = `[data-source-key="${cssEscapeValue(key)}"]`;
      const custom = customEntryFor(key);
      rules.push(
        `html[data-catalog-colors="distinct"] .sharepoint-scope-chip${sel}, html[data-catalog-colors="distinct"] .sp-catalog-badge${sel}, html[data-catalog-colors="distinct"] .sharepoint-scope-color-btn${sel}, html[data-catalog-colors="distinct"] .sharepoint-source-card${sel}, html[data-catalog-colors="distinct"] .sharepoint-source-row${sel} { --catalog-tone: ${hex}; --catalog-tone-ink: ${ink}; }`
      );
      if (custom?.bg || custom?.text) {
        const bg = custom.bg || hex;
        const text = custom.text || contrastInk(bg);
        rules.push(
          `.sharepoint-source-card${sel}[data-card-colors="1"], .sharepoint-source-row${sel}[data-card-colors="1"] { --sp-card-bg: ${bg}; --sp-card-ink: ${text}; --catalog-tone: ${bg}; --catalog-tone-ink: ${text}; }`
        );
      }
    });
    let styleEl = document.getElementById('sharepoint-catalog-color-vars');
    if (!styleEl) {
      styleEl = document.createElement('style');
      styleEl.id = 'sharepoint-catalog-color-vars';
      document.head.appendChild(styleEl);
    }
    styleEl.textContent = rules.join('\n');
    document.querySelectorAll('.sharepoint-scope-color-btn[data-source-key], .sharepoint-card-color-btn[data-source-key]').forEach((btn) => {
      const key = btn.getAttribute('data-source-key') || '';
      if (!key) return;
      const bg = hexForSource(key);
      const ink = textForSource(key);
      btn.style.setProperty('--catalog-tone', bg);
      btn.style.setProperty('--sp-card-bg', bg);
      btn.style.setProperty('--sp-card-ink', ink);
      btn.classList.toggle('is-custom', Boolean(customEntryFor(key)));
    });
    document.querySelectorAll('.sharepoint-source-card[data-source-key], .sharepoint-source-row[data-source-key]').forEach((el) => {
      const key = el.getAttribute('data-source-key') || '';
      if (!key) return;
      const custom = customEntryFor(key);
      const bg = hexForSource(key);
      const ink = textForSource(key);
      el.style.setProperty('--catalog-tone', bg);
      el.style.setProperty('--catalog-tone-ink', ink);
      if (custom?.bg || custom?.text) {
        el.setAttribute('data-card-colors', '1');
        if (custom.bg) el.style.setProperty('--sp-card-bg', custom.bg);
        else el.style.removeProperty('--sp-card-bg');
        el.style.setProperty('--sp-card-ink', custom.text || contrastInk(custom.bg || bg));
      } else {
        el.removeAttribute('data-card-colors');
        el.style.removeProperty('--sp-card-bg');
        el.style.removeProperty('--sp-card-ink');
      }
    });
    syncCatalogColorResetButtons();
  };

  const setCatalogCustomColor = (key, hex, target = 'bg') => {
    const sourceKey = String(key || '').trim();
    const next = normalizeHex(hex);
    if (!sourceKey || !next) return;
    const current = { ...(customEntryFor(sourceKey) || {}) };
    if (target === 'text') {
      current.text = next;
    } else {
      current.bg = next;
    }
    catalogCustomColors[sourceKey] = current;
    saveCustomCatalogColors();
    paintCatalogColorStyles();
  };

  const resetCatalogCustomColor = (key) => {
    const sourceKey = String(key || '').trim();
    if (!sourceKey || !catalogCustomColors[sourceKey]) return;
    delete catalogCustomColors[sourceKey];
    saveCustomCatalogColors();
    paintCatalogColorStyles();
  };

  const resetAllCatalogCustomColors = () => {
    catalogCustomColors = {};
    saveCustomCatalogColors();
    paintCatalogColorStyles();
  };

  const catalogColorPop = () => document.getElementById('sharepoint-catalog-color-pop');

  const closeCatalogColorPop = () => {
    const pop = catalogColorPop();
    if (pop) pop.hidden = true;
    catalogColorPopKey = '';
    document.querySelectorAll('.sharepoint-scope-color-btn[aria-expanded="true"], .sharepoint-card-color-btn[aria-expanded="true"]').forEach((btn) => {
      btn.setAttribute('aria-expanded', 'false');
    });
    catalogColorPopAnchor = null;
  };

  const placeCatalogColorPop = (anchor) => {
    const pop = catalogColorPop();
    if (!pop || !anchor) return;
    pop.hidden = false;
    const rect = anchor.getBoundingClientRect();
    const width = pop.offsetWidth || 232;
    const height = pop.offsetHeight || 360;
    const left = Math.min(window.innerWidth - width - 8, Math.max(8, rect.left));
    let top = rect.bottom + 8;
    if (top + height > window.innerHeight - 8) {
      top = Math.max(8, rect.top - height - 8);
    }
    pop.style.left = `${left}px`;
    pop.style.top = `${top}px`;
  };

  const syncCatalogColorPopSelection = (pop, bgHex, textHex) => {
    const bg = normalizeHex(bgHex);
    const text = normalizeHex(textHex);
    pop.querySelectorAll('.sharepoint-catalog-color-preset').forEach((btn) => {
      const target = btn.getAttribute('data-color-target') || 'bg';
      const hex = normalizeHex(btn.getAttribute('data-hex'));
      btn.classList.toggle('is-selected', target === 'text' ? hex === text : hex === bg);
    });
    const nativeBg = document.getElementById('sharepoint-catalog-color-native');
    const nativeText = document.getElementById('sharepoint-catalog-text-native');
    if (nativeBg && bg) nativeBg.value = bg;
    if (nativeText && text) nativeText.value = text;
  };

  const openCatalogColorPop = (anchor) => {
    const key = String(anchor?.getAttribute('data-source-key') || '').trim();
    const pop = catalogColorPop();
    if (!key || !pop) return;
    const chip = anchor.closest('.sharepoint-scope-chip');
    const card = anchor.closest('.sharepoint-source-card, .sharepoint-source-row');
    const title =
      chip?.querySelector('.sharepoint-scope-chip-main span')?.textContent?.trim() ||
      card?.querySelector('.sharepoint-source-card-title, strong')?.textContent?.trim() ||
      catalogToneByKey[key] ||
      'Catalog';
    const titleEl = document.getElementById('sharepoint-catalog-color-pop-title');
    if (titleEl) titleEl.textContent = title;
    syncCatalogColorPopSelection(pop, hexForSource(key), textForSource(key));
    document.querySelectorAll('.sharepoint-scope-color-btn, .sharepoint-card-color-btn').forEach((btn) => {
      btn.setAttribute('aria-expanded', btn === anchor ? 'true' : 'false');
    });
    catalogColorPopKey = key;
    catalogColorPopAnchor = anchor;
    placeCatalogColorPop(anchor);
  };

  const catalogBadgeHtml = (label, sourceKey) => {
    const text = String(label || '').trim();
    if (!text) return '';
    const key = String(sourceKey || '').trim();
    const tone = catalogToneFor(key, text).replace(/[^a-z0-9_-]/gi, '') || 'slate';
    const srcAttr = key ? ` data-source-key="${escapeHtml(key)}"` : '';
    return `<span class="sp-catalog-badge" data-catalog-tone="${escapeHtml(tone)}"${srcAttr}>${escapeHtml(text)}</span>`;
  };

  const readCatalogColorsOn = () => {
    try {
      return localStorage.getItem(CATALOG_COLORS_KEY) !== '0';
    } catch {
      return true;
    }
  };

  const applyCatalogColors = (on) => {
    const enabled = on !== false;
    document.documentElement.setAttribute('data-catalog-colors', enabled ? 'distinct' : 'uniform');
    document.querySelectorAll('#sharepoint-scopes-colors, #sp-owner-scopes-colors').forEach((btn) => {
      btn.classList.toggle('is-active', enabled);
      btn.setAttribute('aria-pressed', enabled ? 'true' : 'false');
    });
    try {
      localStorage.setItem(CATALOG_COLORS_KEY, enabled ? '1' : '0');
    } catch {
      /* ignore */
    }
    if (!enabled && catalogColorPopAnchor?.classList.contains('sharepoint-scope-color-btn')) {
      closeCatalogColorPop();
    }
    paintCatalogColorStyles();
    return enabled;
  };

  const bindCatalogColorToggle = () => {
    refreshCatalogToneMap();
    catalogCustomColors = readCustomCatalogColors();
    document.querySelectorAll('#sharepoint-scopes-colors, #sp-owner-scopes-colors').forEach((btn) => {
      if (btn.dataset.colorBound === '1') return;
      btn.dataset.colorBound = '1';
      btn.addEventListener('click', () => {
        applyCatalogColors(document.documentElement.getAttribute('data-catalog-colors') !== 'distinct');
      });
    });
    document.querySelectorAll('.sharepoint-scopes-color-reset').forEach((btn) => {
      if (btn.dataset.colorBound === '1') return;
      btn.dataset.colorBound = '1';
      btn.addEventListener('click', () => {
        resetAllCatalogCustomColors();
        closeCatalogColorPop();
      });
    });
    const bindColorAnchor = (btn, { requireDistinct = false } = {}) => {
      if (btn.dataset.colorBound === '1') return;
      btn.dataset.colorBound = '1';
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (requireDistinct && document.documentElement.getAttribute('data-catalog-colors') !== 'distinct') return;
        if (catalogColorPopKey === btn.getAttribute('data-source-key') && catalogColorPop() && !catalogColorPop().hidden) {
          closeCatalogColorPop();
          return;
        }
        openCatalogColorPop(btn);
      });
    };
    document.querySelectorAll('.sharepoint-scope-color-btn').forEach((btn) => bindColorAnchor(btn, { requireDistinct: true }));
    document.querySelectorAll('.sharepoint-card-color-btn').forEach((btn) => bindColorAnchor(btn));
    const pop = catalogColorPop();
    if (pop && pop.dataset.colorBound !== '1') {
      pop.dataset.colorBound = '1';
      pop.addEventListener('click', (event) => event.stopPropagation());
      pop.querySelectorAll('.sharepoint-catalog-color-preset').forEach((btn) => {
        btn.addEventListener('click', () => {
          if (!catalogColorPopKey) return;
          const target = btn.getAttribute('data-color-target') || 'bg';
          setCatalogCustomColor(catalogColorPopKey, btn.getAttribute('data-hex'), target);
          if (catalogColorPopAnchor) openCatalogColorPop(catalogColorPopAnchor);
        });
      });
      const nativeBg = document.getElementById('sharepoint-catalog-color-native');
      nativeBg?.addEventListener('input', () => {
        if (!catalogColorPopKey) return;
        setCatalogCustomColor(catalogColorPopKey, nativeBg.value, 'bg');
        syncCatalogColorPopSelection(pop, nativeBg.value, textForSource(catalogColorPopKey));
      });
      const nativeText = document.getElementById('sharepoint-catalog-text-native');
      nativeText?.addEventListener('input', () => {
        if (!catalogColorPopKey) return;
        setCatalogCustomColor(catalogColorPopKey, nativeText.value, 'text');
        syncCatalogColorPopSelection(pop, hexForSource(catalogColorPopKey), nativeText.value);
      });
      document.getElementById('sharepoint-catalog-color-reset-one')?.addEventListener('click', () => {
        if (!catalogColorPopKey) return;
        resetCatalogCustomColor(catalogColorPopKey);
        if (catalogColorPopAnchor) openCatalogColorPop(catalogColorPopAnchor);
      });
      document.addEventListener('click', (event) => {
        if (!pop || pop.hidden) return;
        if (
          event.target.closest('.sharepoint-scope-color-btn') ||
          event.target.closest('.sharepoint-card-color-btn') ||
          event.target.closest('#sharepoint-catalog-color-pop')
        ) {
          return;
        }
        closeCatalogColorPop();
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeCatalogColorPop();
      });
      window.addEventListener('resize', () => {
        if (catalogColorPopAnchor && pop && !pop.hidden) placeCatalogColorPop(catalogColorPopAnchor);
      });
    }
    applyCatalogColors(readCatalogColorsOn());
  };

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

  const openWorkspaceDialogs = new Set();
  let workspaceSavedScrollY = 0;

  const isScrollableBox = (el) => {
    if (!(el instanceof Element)) return false;
    const style = window.getComputedStyle(el);
    const y = style.overflowY === 'auto' || style.overflowY === 'scroll' || style.overflowY === 'overlay';
    const x = style.overflowX === 'auto' || style.overflowX === 'scroll' || style.overflowX === 'overlay';
    if (y && el.scrollHeight > el.clientHeight + 1) return true;
    if (x && el.scrollWidth > el.clientWidth + 1) return true;
    return false;
  };

  const canScrollInDirection = (el, deltaX, deltaY) => {
    if (!(el instanceof Element)) return false;
    const style = window.getComputedStyle(el);
    const y = style.overflowY === 'auto' || style.overflowY === 'scroll' || style.overflowY === 'overlay';
    const x = style.overflowX === 'auto' || style.overflowX === 'scroll' || style.overflowX === 'overlay';
    const absY = Math.abs(deltaY);
    const absX = Math.abs(deltaX);
    if (absY >= absX) {
      if (!y || el.scrollHeight <= el.clientHeight + 1) return false;
      if (deltaY < 0) return el.scrollTop > 0;
      return el.scrollTop + el.clientHeight < el.scrollHeight - 1;
    }
    if (!x || el.scrollWidth <= el.clientWidth + 1) return false;
    if (deltaX < 0) return el.scrollLeft > 0;
    return el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
  };

  const scrollerFromEvent = (event, root) => {
    const path =
      typeof event.composedPath === 'function'
        ? event.composedPath()
        : (() => {
            const list = [];
            let node = event.target;
            while (node) {
              list.push(node);
              node = node.parentNode || node.host;
            }
            return list;
          })();
    for (const node of path) {
      if (!(node instanceof Element)) continue;
      if (node === root || root.contains(node)) {
        if (isScrollableBox(node)) return node;
      }
      if (node === root) break;
    }
    return null;
  };

  const syncWorkspacePageScroll = () => {
    const anyOpen = openWorkspaceDialogs.size > 0;
    const html = document.documentElement;
    const locked = html.classList.contains('sp-workspace-scroll-lock');
    if (anyOpen && !locked) {
      workspaceSavedScrollY = window.scrollY;
      html.classList.add('sp-workspace-scroll-lock');
      document.body.classList.add('sp-workspace-scroll-lock');
      document.body.style.top = `-${workspaceSavedScrollY}px`;
      return;
    }
    if (!anyOpen && locked) {
      const previousScrollBehavior = html.style.getPropertyValue('scroll-behavior');
      const previousScrollBehaviorPriority = html.style.getPropertyPriority('scroll-behavior');
      html.style.setProperty('scroll-behavior', 'auto', 'important');
      html.classList.remove('sp-workspace-scroll-lock');
      document.body.classList.remove('sp-workspace-scroll-lock');
      document.body.style.top = '';
      window.scrollTo(0, workspaceSavedScrollY);
      window.requestAnimationFrame(() => {
        if (previousScrollBehavior) {
          html.style.setProperty(
            'scroll-behavior',
            previousScrollBehavior,
            previousScrollBehaviorPriority
          );
        } else {
          html.style.removeProperty('scroll-behavior');
        }
      });
    }
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

    const layoutDurationMs = () => {
      const raw = Number.parseFloat(
        getComputedStyle(dialog).getPropertyValue('--sp-dialog-layout-duration')
      );
      return Number.isFinite(raw) && raw > 0 ? raw : 340;
    };

    const playLayoutTransition = (applyNext) => {
      if (!dialog.open || prefersReducedMotion()) {
        applyNext();
        return;
      }
      const from = currentRect();
      dialog.classList.add('is-placed');
      dialog.style.transform = 'none';
      dialog.style.right = '';
      dialog.style.bottom = '';
      dialog.style.left = `${Math.round(from.left)}px`;
      dialog.style.top = `${Math.round(from.top)}px`;
      dialog.style.width = `${Math.round(from.width)}px`;
      dialog.style.height = `${Math.round(from.height)}px`;
      dialog.classList.remove('is-layout-animating');
      void dialog.offsetWidth;
      dialog.classList.add('is-layout-animating');
      applyNext();
      let settled = false;
      const settle = () => {
        if (settled) return;
        settled = true;
        dialog.removeEventListener('transitionend', onEnd);
        dialog.classList.remove('is-layout-animating');
      };
      const onEnd = (event) => {
        if (event.target !== dialog) return;
        if (!['left', 'top', 'width', 'height', 'border-radius'].includes(event.propertyName)) {
          return;
        }
        settle();
      };
      dialog.addEventListener('transitionend', onEnd);
      window.setTimeout(settle, layoutDurationMs() + 80);
    };

    const applyMaximizedStyles = () => {
      dialog.classList.add('is-maximized', 'is-placed');
      dialog.style.transform = 'none';
      dialog.style.left = '0px';
      dialog.style.top = '0px';
      dialog.style.width = '100vw';
      dialog.style.height = '100vh';
      dialog.style.right = '0px';
      dialog.style.bottom = '0px';
    };

    const setMaximized = (next, { animate = false } = {}) => {
      if (dialog.classList.contains('is-layout-animating')) return;
      const apply = () => {
        if (next) {
          if (!maximized) {
            if (dialog.open) rememberRect();
            savedRect = lastRect || savedRect;
          }
          maximized = true;
          applyMaximizedStyles();
        } else {
          maximized = false;
          dialog.classList.remove('is-maximized');
          if (isUsableRect(savedRect) || isUsableRect(lastRect)) applyRect(savedRect || lastRect);
          else centerDefault();
        }
        syncMaximizeBtn();
        schedulePersist();
      };
      if (animate && dialog.open) playLayoutTransition(apply);
      else apply();
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
      setMaximized(!maximized, { animate: true });
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
      setMaximized(!maximized, { animate: true });
    });

    if (typeof ResizeObserver === 'function') {
      const resizeObserver = new ResizeObserver(() => {
        if (!dialog.open || maximized || drag || dialog.classList.contains('is-layout-animating')) {
          return;
        }
        rememberRect();
        schedulePersist();
      });
      resizeObserver.observe(dialog);
    }

    const syncPageScrollLock = () => {
      if (dialog.open) openWorkspaceDialogs.add(dialog);
      else openWorkspaceDialogs.delete(dialog);
      syncWorkspacePageScroll();
    };

    const trapBackgroundScroll = (event) => {
      if (!dialog.open) return;
      const scroller = scrollerFromEvent(event, dialog);
      if (event.type === 'wheel') {
        if (scroller && canScrollInDirection(scroller, event.deltaX || 0, event.deltaY || 0)) {
          return;
        }
      } else if (scroller) {
        return;
      }
      event.preventDefault();
    };

    document.addEventListener('wheel', trapBackgroundScroll, { passive: false, capture: true });
    document.addEventListener('touchmove', trapBackgroundScroll, { passive: false, capture: true });

    dialog.addEventListener('toggle', syncPageScrollLock);
    dialog.addEventListener('close', () => {
      drag = null;
      dialog.classList.remove('is-dragging', 'is-leaving', 'is-entering', 'is-layout-animating');
      persistLayout();
      if (maximized) dialog.classList.remove('is-maximized');
      syncPageScrollLock();
    });

    const nativeClose = typeof dialog.close === 'function' ? dialog.close.bind(dialog) : null;
    let leavePromise = null;
    const requestClose = (returnValue) => {
      if (returnValue !== undefined) {
        dialog.returnValue = String(returnValue);
      }
      if (!dialog.open) return;
      if (leavePromise) return leavePromise;
      leavePromise = playWorkspaceDialogLeave(dialog)
        .catch(() => {})
        .then(() => {
          leavePromise = null;
          if (!dialog.open) return;
          if (nativeClose) nativeClose();
          else dialog.removeAttribute('open');
        });
      return leavePromise;
    };
    if (nativeClose) {
      dialog.close = requestClose;
    }

    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      if (dialog.getAttribute('data-require-close-btn') === '1') return;
      requestClose();
    });

    dialog.addEventListener(
      'keydown',
      (event) => {
        if (event.key !== 'Escape' || event.isComposing || !dialog.open) return;
        event.preventDefault();
        event.stopPropagation();
        // Search stats dashboard stays open until the Close button is used.
        if (dialog.getAttribute('data-require-close-btn') === '1') return;
        requestClose();
      },
      true
    );

    dialog.__spPrepareWorkspace = () => {
      restoreLayout();
      syncPageScrollLock();
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

  const catalogApiUrl = (action, extra = {}) => {
    const root = document.getElementById('sharepoint-search');
    const base = (root?.getAttribute('data-api-base') || 'sharepoint.php').trim() || 'sharepoint.php';
    const token = (root?.getAttribute('data-share-token') || '').trim();
    const params = new URLSearchParams();
    params.set('action', String(action || ''));
    if (token) params.set('t', token);
    Object.entries(extra || {}).forEach(([key, value]) => {
      if (value === undefined || value === null || String(value) === '') return;
      params.set(key, String(value));
    });
    return `${base}?${params.toString()}`;
  };

  const fetchProjectDetail = async (projectName, sourceKey = '', options = {}) => {
    const name = String(projectName || '').trim();
    if (!name) throw new Error('Missing project name.');
    const resolvedSource =
      String(sourceKey || '').trim() ||
      document.getElementById('sharepoint-search')?.getAttribute('data-source-key') ||
      '';
    const extra = { name };
    if (resolvedSource) extra.source = resolvedSource;
    if (options.fresh) extra._ts = String(Date.now());
    const response = await fetch(catalogApiUrl('project_detail', extra), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json', 'Cache-Control': 'no-cache' },
    });
    const payload = await response.json();
    if (!response.ok || !payload.ok || !payload.project) {
      throw new Error(payload.error || 'Unable to load project details.');
    }
    return payload;
  };

  const catalogCsrfToken = () =>
    document.getElementById('sharepoint-search')?.getAttribute('data-csrf') ||
    document.getElementById('sharepoint-sources')?.getAttribute('data-csrf') ||
    document.getElementById('sharepoint-msal-sync')?.getAttribute('data-csrf') ||
    '';

  const catalogCanEditTags = () =>
    document.getElementById('sharepoint-search')?.getAttribute('data-can-edit-tags') === '1';

  const catalogCanArchive = () =>
    document.getElementById('sharepoint-search')?.getAttribute('data-can-archive') === '1';

  const catalogIsPublic = () =>
    document.getElementById('sharepoint-search')?.getAttribute('data-public') === '1';

  const catalogCanFavorite = () => !catalogIsPublic() && !!catalogCsrfToken();

  const catalogShowArchived = () =>
    document.getElementById('sharepoint-search')?.getAttribute('data-show-archived') === '1';

  const favoriteToggleHtml = ({
    favorited = false,
    title = '',
    extraClass = '',
    attrs = {},
  } = {}) => {
    if (!catalogCanFavorite()) return '';
    const isOn = !!favorited;
    const label = isOn ? 'Remove from favorites' : 'Add to favorites';
    const icon = isOn ? '★' : '☆';
    const attrHtml = Object.entries(attrs || {})
      .map(([key, value]) => ` ${key}="${escapeHtml(String(value ?? ''))}"`)
      .join('');
    return `<button type="button" class="sp-favorite-btn ${extraClass}${isOn ? ' is-on' : ''}" data-favorited="${isOn ? '1' : '0'}" title="${escapeHtml(title || label)}" aria-label="${escapeHtml(label)}" aria-pressed="${isOn ? 'true' : 'false'}"${attrHtml} onclick="event.stopPropagation()">${icon}</button>`;
  };

  const postFavorite = async ({ scope, sourceKey, projectName = '', favorited }) =>
    postCatalogAction('set_favorite', {
      scope,
      source_key: sourceKey,
      project_name: projectName || '',
      favorited: favorited ? '1' : '0',
    });

  const applyFavoriteButtonState = (btn, favorited) => {
    if (!btn) return;
    const isOn = !!favorited;
    btn.classList.toggle('is-on', isOn);
    btn.setAttribute('data-favorited', isOn ? '1' : '0');
    btn.setAttribute('aria-pressed', isOn ? 'true' : 'false');
    const label = isOn ? 'Remove from favorites' : 'Add to favorites';
    btn.setAttribute('title', label);
    btn.setAttribute('aria-label', label);
    btn.textContent = isOn ? '★' : '☆';
  };

  const archiveToggleHtml = ({
    archived = false,
    disabled = false,
    title = '',
    extraClass = '',
    attrs = {},
  } = {}) => {
    if (!catalogCanArchive()) return '';
    const isArchived = !!archived;
    const label = isArchived ? 'Unarchive' : 'Archive';
    const icon = isArchived ? '↩️' : '📦';
    const attrHtml = Object.entries(attrs || {})
      .map(([key, value]) => ` ${key}="${escapeHtml(String(value ?? ''))}"`)
      .join('');
    return `<button type="button" class="button ghost-light sp-archive-btn ${extraClass}${isArchived ? ' is-on' : ''}" data-archived="${isArchived ? '1' : '0'}" title="${escapeHtml(title || label)}" aria-label="${escapeHtml(label)}"${attrHtml}${disabled ? ' disabled' : ''} onclick="event.stopPropagation()">${icon}</button>`;
  };

  const archivedBadgeHtml = (reason = '') =>
    `<span class="sp-archive-badge" title="${escapeHtml(reason || 'Hidden from the catalog dashboard')}">📦 Archived</span>`;

  const postArchive = async ({ scope, sourceKey, projectName, relativePath = '', archived }) =>
    postCatalogAction('set_archive', {
      scope,
      source_key: sourceKey,
      project_name: projectName || '',
      relative_path: relativePath || '',
      archived: archived ? '1' : '0',
    });

  const postCatalogAction = async (action, fields = {}) => {
    const body = new URLSearchParams();
    body.set('csrf_token', catalogCsrfToken());
    body.set('action', action);
    body.set('ajax', '1');
    Object.entries(fields || {}).forEach(([key, value]) => {
      if (value === undefined || value === null) return;
      if (Array.isArray(value)) {
        value.forEach((item) => body.append(`${key}[]`, String(item)));
        return;
      }
      body.set(key, String(value));
    });
    const response = await fetch('sharepoint.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      },
      body: body.toString(),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload?.ok) {
      throw new Error(payload?.error || 'Request failed.');
    }
    return payload;
  };

  const normalizeTagList = (tags) =>
    (Array.isArray(tags) ? tags : [])
      .map((tag) => ({
        id: Number(tag?.id || 0),
        label: String(tag?.label || '').trim(),
        slug: String(tag?.slug || '').trim().toLowerCase(),
      }))
      .filter((tag) => tag.id > 0 && tag.label);

  const tagChipsHtml = (tags, { editable = false, scope = 'project', path = '' } = {}) => {
    const list = normalizeTagList(tags);
    if (!list.length && !editable) return '';
    const chips = list
      .map((tag) => {
        const remove = editable
          ? `<button type="button" class="sp-tag-remove" data-tag-id="${tag.id}" data-tag-scope="${escapeHtml(scope)}" data-tag-path="${escapeHtml(path)}" title="Remove tag" aria-label="Remove ${escapeHtml(tag.label)}">×</button>`
          : '';
        return `<span class="sp-tag-chip" data-tag-id="${tag.id}" data-tag-slug="${escapeHtml(tag.slug)}">${escapeHtml(tag.label)}${remove}</span>`;
      })
      .join('');
    return `<div class="sp-tag-chips" data-tag-scope="${escapeHtml(scope)}" data-tag-path="${escapeHtml(path)}">${chips}</div>`;
  };

  const projectHasTagNeedle = (project, needle) => {
    const want = String(needle || '')
      .trim()
      .toLowerCase();
    if (!want) return true;
    const hay = String(project?._hayTags || '');
    if (!hay) return false;
    return hay.split('\n').some((line) => line === want || line.includes(want));
  };

  const collectSearchTagNeedles = (parsed) => {
    const needles = [];
    const push = (value) => {
      const want = String(value || '')
        .trim()
        .toLowerCase();
      if (!want || needles.includes(want)) return;
      needles.push(want);
    };
    (parsed?.tags || []).forEach(push);
    if (catalogSearchState?.tagFilter) push(catalogSearchState.tagFilter);
    (parsed?.words || []).forEach(push);
    (parsed?.phrases || []).forEach(push);
    return needles;
  };

  const matchedProjectTagLabels = (project, needles) => {
    if (!needles.length) return [];
    const seen = new Set();
    const labels = [];
    const consider = (label) => {
      const text = String(label || '').trim();
      const lower = text.toLowerCase();
      if (!text || seen.has(lower)) return;
      if (!needles.some((needle) => lower === needle || lower.includes(needle))) return;
      seen.add(lower);
      labels.push(text);
    };
    const fields = Array.isArray(project?._tagFields) ? project._tagFields : [];
    if (fields.length) {
      fields.forEach((field) => consider(field.text));
      return labels;
    }
    String(project?._hayTags || '')
      .split('\n')
      .forEach(consider);
    return labels;
  };

  const attachTagMatchMeta = (project, match, parsed, refineParsed = null) => {
    if (!match?.matched) return match;
    const needles = collectSearchTagNeedles(parsed);
    if (refineParsed) {
      collectSearchTagNeedles(refineParsed).forEach((needle) => {
        if (!needles.includes(needle)) needles.push(needle);
      });
    }
    const labels = matchedProjectTagLabels(project, needles);
    match.tagMatched = labels.length > 0;
    match.matchedTags = labels;
    if (match.tagMatched && match.source === 'Tag' && !match.sourceName && labels[0]) {
      match.sourceName = labels[0];
    }
    return match;
  };

  const setDialogRefreshBusy = (btn, busy) => {
    if (!btn) return;
    btn.disabled = !!busy;
    btn.classList.toggle('is-refreshing', !!busy);
    btn.setAttribute('aria-busy', busy ? 'true' : 'false');
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

  /** Path inside a project folder, so compare can match the same file across two projects. */
  const projectRelativePath = (item, projectName = '') => {
    const path = normalizeRelPath(item?.relative_path || item?.name || '');
    const project = normalizeRelPath(projectName || item?.project_name || '');
    if (!path) return '';
    if (!project) return path;
    const pathLower = path.toLowerCase();
    const projectLower = project.toLowerCase();
    if (pathLower === projectLower) return '';
    if (pathLower.startsWith(`${projectLower}/`)) {
      return path.slice(project.length + 1);
    }
    return path;
  };

  const compareItemKey = (item, projectName = '') => {
    const path = projectRelativePath(item, projectName).toLowerCase();
    const type = String(item?.item_type || 'file').toLowerCase();
    return `${type}::${path}`;
  };

  const SEARCH_PREF = {
    wordMode: 'riskregister_sp_search_word_mode',
    fuzzy: 'riskregister_sp_search_fuzzy',
    deep: 'riskregister_sp_search_deep',
    compareDensity: 'riskregister_sp_compare_density',
    compareColumns: 'riskregister_sp_compare_columns',
    projectColumns: 'riskregister_sp_project_columns',
    projectColWidths: 'riskregister_sp_project_col_widths',
    compareColWidths: 'riskregister_sp_compare_col_widths',
    listColWidths: 'riskregister_sp_list_col_widths',
    listColOrder: 'riskregister_sp_list_col_order',
    listDensity: 'riskregister_sp_list_density',
    listColumns: 'riskregister_sp_list_columns',
    catalogDensity: 'riskregister_sp_catalog_density',
  };

  const COMPARE_TOGGLE_COLS = ['type', 'size', 'modified', 'created', 'modified_by', 'created_by', 'diff', 'actions', 'archive'];
  const COMPARE_DEFAULT_HIDDEN_COLS = ['size', 'modified_by'];
  const PROJECT_TOGGLE_COLS = ['type', 'size', 'modified', 'created', 'modified_by', 'created_by', 'actions', 'archive'];
  const PROJECT_DEFAULT_HIDDEN_COLS = [];
  const LIST_TOGGLE_COLS = ['match', 'items', 'modified', 'modified_by', 'created_by', 'actions'];
  const LIST_DEFAULT_HIDDEN_COLS = [];
  const LIST_FIXED_COLS = ['select', 'name'];
  const LIST_FIXED_COL_COUNT = LIST_FIXED_COLS.length;

  const readHiddenCols = (storageKey, allowed, fallback) => {
    try {
      const raw = localStorage.getItem(storageKey);
      if (!raw) return new Set(fallback);
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return new Set(fallback);
      return new Set(parsed.filter((col) => allowed.includes(col)));
    } catch {
      return new Set(fallback);
    }
  };

  const writeHiddenCols = (storageKey, hidden) => {
    try {
      localStorage.setItem(storageKey, JSON.stringify([...hidden]));
    } catch {
      /* ignore */
    }
  };

  const readCompareHiddenCols = () =>
    readHiddenCols(SEARCH_PREF.compareColumns, COMPARE_TOGGLE_COLS, COMPARE_DEFAULT_HIDDEN_COLS);

  const writeCompareHiddenCols = (hidden) => writeHiddenCols(SEARCH_PREF.compareColumns, hidden);

  const readProjectHiddenCols = () =>
    readHiddenCols(SEARCH_PREF.projectColumns, PROJECT_TOGGLE_COLS, PROJECT_DEFAULT_HIDDEN_COLS);

  const writeProjectHiddenCols = (hidden) => writeHiddenCols(SEARCH_PREF.projectColumns, hidden);

  const MIN_DIALOG_COL_PX = {
    name: 148,
    type: 72,
    size: 68,
    modified: 100,
    created: 100,
    modified_by: 108,
    created_by: 108,
    actions: 132,
    archive: 76,
    diff: 72,
    hide: 36,
    select: 40,
    match: 100,
    items: 88,
  };

  const bindColumnResize = (host, options = {}) => {
    const storageKey = typeof options === 'string' ? options : options.storageKey;
    const tableSelector =
      typeof options === 'string' ? '.sharepoint-dialog-table' : options.tableSelector || '.sharepoint-dialog-table';
    const handleRowSelector =
      typeof options === 'string' ? 'thead tr' : options.handleRowSelector || 'thead tr';
    const tables = host instanceof Element ? [...host.querySelectorAll(tableSelector)] : [];
    if (host instanceof HTMLTableElement && tables.length === 0) tables.push(host);
    if (!host || !storageKey || tables.length === 0) {
      return { apply: () => {} };
    }

    const readWidths = () => {
      try {
        const raw = JSON.parse(localStorage.getItem(storageKey) || 'null');
        if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return {};
        const next = {};
        Object.entries(raw).forEach(([col, value]) => {
          const width = Number(value);
          if (col && Number.isFinite(width) && width >= 36) {
            next[col] = Math.round(width);
          }
        });
        return next;
      } catch {
        return {};
      }
    };

    let widths = readWidths();
    let persistTimer = 0;

    const persist = () => {
      window.clearTimeout(persistTimer);
      persistTimer = window.setTimeout(() => {
        try {
          localStorage.setItem(storageKey, JSON.stringify(widths));
        } catch {
          /* ignore */
        }
      }, 120);
    };

    const apply = () => {
      tables.forEach((table) => {
        table.classList.add('is-col-resizable');
        table.querySelectorAll('th[data-col]').forEach((th) => {
          const col = th.getAttribute('data-col') || '';
          const width = widths[col];
          if (width) {
            th.style.width = `${width}px`;
            th.style.minWidth = `${width}px`;
          } else {
            th.style.width = '';
            th.style.minWidth = '';
          }
        });
      });
    };

    const setWidth = (col, px) => {
      if (!col) return;
      const min = MIN_DIALOG_COL_PX[col] || 56;
      widths[col] = Math.min(760, Math.max(min, Math.round(px)));
      apply();
    };

    const resetWidth = (col) => {
      if (!col || !(col in widths)) return;
      delete widths[col];
      apply();
      persist();
    };

    const startResize = (event) => {
      const handle = event.currentTarget;
      const th = handle instanceof Element ? handle.closest('th[data-col]') : null;
      const col = th?.getAttribute('data-col') || '';
      if (!col || event.button !== 0) return;
      event.preventDefault();
      event.stopPropagation();
      const startX = event.clientX;
      const startW = th.getBoundingClientRect().width;
      host.classList.add('is-col-resizing');
      handle.classList.add('is-active');
      try {
        handle.setPointerCapture(event.pointerId);
      } catch {
        /* ignore */
      }

      const onMove = (moveEvent) => {
        if (moveEvent.pointerId !== event.pointerId) return;
        setWidth(col, startW + (moveEvent.clientX - startX));
      };
      const onUp = (upEvent) => {
        if (upEvent.pointerId !== event.pointerId) return;
        handle.removeEventListener('pointermove', onMove);
        handle.removeEventListener('pointerup', onUp);
        handle.removeEventListener('pointercancel', onUp);
        handle.classList.remove('is-active');
        host.classList.remove('is-col-resizing');
        persist();
        try {
          handle.releasePointerCapture(upEvent.pointerId);
        } catch {
          /* ignore */
        }
      };
      handle.addEventListener('pointermove', onMove);
      handle.addEventListener('pointerup', onUp);
      handle.addEventListener('pointercancel', onUp);
    };

    tables.forEach((table) => {
      const handleRow = table.querySelector(handleRowSelector) || table.querySelector('thead tr');
      handleRow?.querySelectorAll('th[data-col]').forEach((th) => {
        if (th.querySelector('.sp-col-resize-handle')) return;
        const handle = document.createElement('span');
        handle.className = 'sp-col-resize-handle';
        handle.setAttribute('role', 'separator');
        handle.setAttribute('aria-orientation', 'vertical');
        handle.setAttribute('aria-label', `Resize ${th.getAttribute('data-col') || 'column'}`);
        handle.title = 'Drag to resize. Double-click to reset.';
        handle.addEventListener('pointerdown', startResize);
        handle.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
        });
        handle.addEventListener('dblclick', (event) => {
          event.preventDefault();
          event.stopPropagation();
          resetWidth(th.getAttribute('data-col') || '');
        });
        th.appendChild(handle);
      });
    });

    apply();
    return { apply };
  };

  const readListHiddenCols = () =>
    readHiddenCols(SEARCH_PREF.listColumns, LIST_TOGGLE_COLS, LIST_DEFAULT_HIDDEN_COLS);

  const writeListHiddenCols = (hidden) => writeHiddenCols(SEARCH_PREF.listColumns, hidden);

  const readColOrder = (storageKey, allowed) => {
    try {
      const raw = JSON.parse(localStorage.getItem(storageKey) || 'null');
      if (!Array.isArray(raw)) return [...allowed];
      const seen = new Set();
      const next = [];
      raw.forEach((col) => {
        if (allowed.includes(col) && !seen.has(col)) {
          seen.add(col);
          next.push(col);
        }
      });
      allowed.forEach((col) => {
        if (!seen.has(col)) next.push(col);
      });
      return next;
    } catch {
      return [...allowed];
    }
  };

  const writeColOrder = (storageKey, order) => {
    try {
      localStorage.setItem(storageKey, JSON.stringify(order));
    } catch {
      /* ignore */
    }
  };

  /** @type {Set<string>} */
  let listHiddenCols = readListHiddenCols();
  let listColOrder = readColOrder(SEARCH_PREF.listColOrder, LIST_TOGGLE_COLS);

  const listColumnSequence = () => [...LIST_FIXED_COLS, ...listColOrder];

  const applyListColumnOrder = () => {
    const table = document.getElementById('sharepoint-projects-table');
    if (!table) return;
    const rank = new Map(listColumnSequence().map((col, index) => [col, index]));
    table.querySelectorAll('thead tr, tbody tr').forEach((row) => {
      const cells = [...row.children].filter((cell) => cell.hasAttribute?.('data-col'));
      if (cells.length < 2) return;
      cells
        .slice()
        .sort((left, right) => {
          const leftRank = rank.get(left.getAttribute('data-col') || '') ?? 99;
          const rightRank = rank.get(right.getAttribute('data-col') || '') ?? 99;
          return leftRank - rightRank;
        })
        .forEach((cell) => row.appendChild(cell));
    });
  };

  const syncListColumnMenuOrder = () => {
    const menu = document.getElementById('sharepoint-list-columns-menu');
    if (!menu) return;
    listColOrder.forEach((col) => {
      const row = menu.querySelector(`[data-col-row="${col}"]`);
      if (row) menu.appendChild(row);
    });
    menu.querySelectorAll('[data-col-row]').forEach((row) => {
      const col = row.getAttribute('data-col-row') || '';
      const index = listColOrder.indexOf(col);
      const up = row.querySelector('[data-col-move="up"]');
      const down = row.querySelector('[data-col-move="down"]');
      if (up instanceof HTMLButtonElement) up.disabled = index <= 0;
      if (down instanceof HTMLButtonElement) down.disabled = index < 0 || index >= listColOrder.length - 1;
    });
  };

  const moveListColumn = (col, delta) => {
    const index = listColOrder.indexOf(col);
    const nextIndex = index + delta;
    if (index < 0 || nextIndex < 0 || nextIndex >= listColOrder.length) return;
    const next = listColOrder.slice();
    const [item] = next.splice(index, 1);
    next.splice(nextIndex, 0, item);
    listColOrder = next;
    writeColOrder(SEARCH_PREF.listColOrder, listColOrder);
    syncListColumnMenuOrder();
    applyListColumnOrder();
    listColResize.apply();
  };

  const listVisibleColspan = () => {
    let count = LIST_FIXED_COL_COUNT;
    LIST_TOGGLE_COLS.forEach((col) => {
      if (!listHiddenCols.has(col)) count += 1;
    });
    return Math.max(count, LIST_FIXED_COL_COUNT);
  };

  const closeColumnsPicker = (picker) => {
    if (!picker) return;
    if (picker instanceof HTMLDetailsElement) {
      picker.open = false;
      return;
    }
    const menu = picker.querySelector('.sp-compare-columns-menu');
    const toggle = picker.querySelector('[aria-haspopup="true"]');
    if (menu) menu.hidden = true;
    if (toggle) {
      toggle.classList.remove('is-active');
      toggle.setAttribute('aria-expanded', 'false');
    }
  };

  const bindColumnsMenuClose = (picker) => {
    if (!picker || picker.dataset.columnsCloseBound === '1') return;
    picker.dataset.columnsCloseBound = '1';
    picker.querySelectorAll('[data-columns-close]').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        closeColumnsPicker(picker);
      });
    });
  };

  const applyListHiddenCols = () => {
    const card = document.getElementById('sharepoint-table-card');
    card?.setAttribute('data-hidden-cols', [...listHiddenCols].join(' '));
    document.getElementById('sharepoint-list-columns-picker')?.querySelectorAll('input[data-col-toggle]').forEach((input) => {
      const col = input.getAttribute('data-col-toggle') || '';
      input.checked = !listHiddenCols.has(col);
    });
    const span = listVisibleColspan();
    document.querySelectorAll('#sharepoint-projects-tbody .sharepoint-empty-row td').forEach((cell) => {
      cell.colSpan = span;
    });
    syncListColumnMenuOrder();
    applyListColumnOrder();
    listColResize.apply();
  };

  const listColResize = bindColumnResize(
    document.getElementById('sharepoint-table-card') || document.getElementById('sharepoint-projects-table'),
    {
      storageKey: SEARCH_PREF.listColWidths,
      tableSelector: '#sharepoint-projects-table',
      handleRowSelector: 'thead tr:not(.sharepoint-table-filters)',
    }
  );

  const bindListColumnsPicker = () => {
    const picker = document.getElementById('sharepoint-list-columns-picker');
    const toggle = document.getElementById('sharepoint-list-columns-toggle');
    const menu = document.getElementById('sharepoint-list-columns-menu');
    if (!picker || !toggle || !menu || picker.dataset.columnsBound === '1') return;
    picker.dataset.columnsBound = '1';

    const setPickerOpen = (open) => {
      menu.hidden = !open;
      toggle.classList.toggle('is-active', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      setPickerOpen(menu.hidden);
    });
    picker.addEventListener('click', (event) => {
      event.stopPropagation();
    });
    picker.querySelectorAll('input[data-col-toggle]').forEach((input) => {
      input.addEventListener('change', () => {
        const col = input.getAttribute('data-col-toggle') || '';
        if (!LIST_TOGGLE_COLS.includes(col)) return;
        if (input.checked) listHiddenCols.delete(col);
        else listHiddenCols.add(col);
        writeListHiddenCols(listHiddenCols);
        applyListHiddenCols();
      });
    });
    picker.querySelectorAll('[data-col-move]').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const col = btn.getAttribute('data-col') || '';
        const delta = btn.getAttribute('data-col-move') === 'up' ? -1 : 1;
        moveListColumn(col, delta);
      });
    });
    picker.querySelector('.sp-compare-columns-menu')?.addEventListener('click', (event) => {
      event.stopPropagation();
    });
    bindColumnsMenuClose(picker);
    document.addEventListener('click', (event) => {
      if (menu.hidden) return;
      if (picker.contains(event.target)) return;
      setPickerOpen(false);
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') setPickerOpen(false);
    });
    document.getElementById('sharepoint-catalog-table-shell')?.addEventListener('toggle', () => {
      const shell = document.getElementById('sharepoint-catalog-table-shell');
      if (shell && !shell.open) setPickerOpen(false);
    });
    applyListHiddenCols();
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
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        applyListDensity(btn.getAttribute('data-list-density') || 'compact');
      });
    });
    applyListDensity(readListDensity());
  };

  const readCatalogDensity = () => {
    try {
      return localStorage.getItem(SEARCH_PREF.catalogDensity) === 'comfort' ? 'comfort' : 'compact';
    } catch {
      return 'compact';
    }
  };

  const applyCatalogDensity = (next) => {
    const density = next === 'comfort' ? 'comfort' : 'compact';
    const card = document.getElementById('sharepoint-search');
    if (card) {
      card.classList.toggle('is-compact-chrome', density === 'compact');
      card.setAttribute('data-catalog-density', density);
      card.querySelectorAll('.sp-view-btn[data-catalog-density]').forEach((btn) => {
        const active = btn.getAttribute('data-catalog-density') === density;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    }
    try {
      localStorage.setItem(SEARCH_PREF.catalogDensity, density);
    } catch {
      /* ignore */
    }
    return density;
  };

  const bindCatalogDensityToggle = () => {
    const card = document.getElementById('sharepoint-search');
    if (!card || card.dataset.catalogDensityBound === '1') return;
    card.dataset.catalogDensityBound = '1';
    card.querySelectorAll('.sp-view-btn[data-catalog-density]').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        applyCatalogDensity(btn.getAttribute('data-catalog-density') || 'compact');
      });
    });
    applyCatalogDensity(readCatalogDensity());
  };

  const readSearchPrefs = () => ({
    wordMode: localStorage.getItem(SEARCH_PREF.wordMode) === 'or' ? 'or' : 'and',
    fuzzy: localStorage.getItem(SEARCH_PREF.fuzzy) === '1',
    deep: localStorage.getItem(SEARCH_PREF.deep) !== '0',
  });

  const writeSearchPrefs = (prefs) => {
    localStorage.setItem(SEARCH_PREF.wordMode, prefs.wordMode === 'or' ? 'or' : 'and');
    localStorage.setItem(SEARCH_PREF.fuzzy, prefs.fuzzy ? '1' : '0');
    if (prefs.deep !== undefined) {
      localStorage.setItem(SEARCH_PREF.deep, prefs.deep ? '1' : '0');
    }
  };

  const DIALOG_IMAGE_EXTS = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp']);
  const DIALOG_CAD_EXTS = new Set(['dwg', 'dxf']);
  const DIALOG_VISIO_EXTS = new Set(['vsdx', 'vsd']);
  /** Auto-expand matching folders only when the full match tree stays this small. */
  const DIALOG_TREE_EXPAND_ROW_CAP = 400;
  /** Skip FLIP settle animations above this many visible rows. */
  const DIALOG_SETTLE_ROW_CAP = 250;

  const isFolderItem = (item) => String(item?.item_type || '').toLowerCase() === 'folder';

  const emptyDialogParsedQuery = () => ({
    words: [],
    phrases: [],
    excludes: [],
    extensions: [],
    types: [],
    person: '',
    modifiedBy: '',
    createdBy: '',
    paths: [],
    has: [],
    lacks: [],
    tags: [],
  });

  const parseDialogQuery = (query) => {
    if (query && typeof query === 'object' && Array.isArray(query.words)) return query;
    if (Fuzzy?.parseCatalogQuery) return Fuzzy.parseCatalogQuery(query);
    const words = Fuzzy?.getSearchWords
      ? Fuzzy.getSearchWords(query)
      : String(query || '')
          .toLowerCase()
          .split(/\s+/)
          .filter(Boolean);
    return { ...emptyDialogParsedQuery(), words };
  };

  const dialogQueryIsActive = (parsed) =>
    !!(
      parsed?.words?.length ||
      parsed?.phrases?.length ||
      parsed?.excludes?.length ||
      parsed?.extensions?.length ||
      parsed?.types?.length ||
      parsed?.paths?.length ||
      parsed?.has?.length ||
      parsed?.lacks?.length ||
      (parsed?.tags && parsed.tags.length) ||
      parsed?.person ||
      parsed?.modifiedBy ||
      parsed?.createdBy
    );

  const dialogHayHasWords = (hay, words, mode) => {
    if (!hay) return false;
    if (!words.length) return true;
    if (mode === 'or') return words.some((word) => hay.includes(word));
    return words.every((word) => hay.includes(word));
  };

  const dialogResolveMeAlias = (value) => {
    const raw = String(value || '')
      .trim()
      .toLowerCase();
    if (raw !== 'me') return raw;
    const display = String(currentUser?.display || currentUser?.name || currentUser?.email || '').trim().toLowerCase();
    return display || 'me';
  };

  const itemTagSearchFields = (item) =>
    normalizeTagList(item?.tags).map((tag) => ({
      text: tag.label,
      sourceLabel: 'Tag',
      sourceName: tag.label,
    }));

  const itemSearchFields = (item) => {
    if (Array.isArray(item?._fields) && item._fields.length) return item._fields;
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
      ...itemTagSearchFields(item),
    ];
  };

  const collectItemSelfTraits = (item, name, path, ext) => {
    const traits = new Set();
    if (isFolderItem(item)) {
      traits.add('folders');
      return traits;
    }
    if (ext) traits.add(ext);
    if (ext === 'pdf') traits.add('pdf');
    if (DIALOG_VISIO_EXTS.has(ext)) traits.add('visio');
    if (DIALOG_CAD_EXTS.has(ext)) {
      traits.add('cad');
      traits.add('drawings');
    }
    if (DIALOG_IMAGE_EXTS.has(ext)) traits.add('images');
    if (/\bdrawings?\b/i.test(`${name}\n${path}`)) traits.add('drawings');
    return traits;
  };

  const prepareSearchItem = (item, { force = false } = {}) => {
    if (!item || (item._searchReady && !force)) return item;
    const name = String(item?.name || '');
    const path = String(item?.relative_path || '');
    const meta = resolveMeta(item);
    const ext = fileExtension(name);
    const tagFields = itemTagSearchFields(item);
    const fields = [
      { text: name, sourceLabel: 'Name', sourceName: 'name' },
      { text: path, sourceLabel: 'Path', sourceName: 'path' },
      { text: ext, sourceLabel: 'Extension', sourceName: 'ext' },
      { text: ext ? `.${ext}` : '', sourceLabel: 'Extension', sourceName: 'ext_dot' },
      { text: meta.label, sourceLabel: 'Type', sourceName: 'type_label' },
      { text: String(item?.item_type || ''), sourceLabel: 'Type', sourceName: 'item_type' },
      { text: String(item?.modified_by || ''), sourceLabel: 'Modified by', sourceName: 'modified_by' },
      { text: String(item?.person || ''), sourceLabel: 'Created By', sourceName: 'person' },
      { text: String(item?.mime_type || ''), sourceLabel: 'MIME', sourceName: 'mime' },
      ...tagFields,
    ];
    const tagBits = [];
    normalizeTagList(item.tags).forEach((tag) => {
      tagBits.push(tag.label.toLowerCase(), tag.slug);
    });
    const hayParts = fields
      .map((field) => String(field.text || '').toLowerCase())
      .filter(Boolean)
      .concat(tagBits);
    item._fields = fields;
    item._hay = hayParts.join('\n');
    item._pathHay = path.toLowerCase();
    item._tagHay = [...new Set(tagBits.filter(Boolean))].join('\n');
    item._ext = ext;
    const selfTraits = collectItemSelfTraits(item, name, path, ext);
    item._selfTraits = [...selfTraits];
    item._traits = new Set(selfTraits);
    item._searchReady = true;
    return item;
  };

  const prepareSearchItems = (items) => {
    const list = Array.isArray(items) ? items : [];
    list.forEach((item) => prepareSearchItem(item, { force: true }));
    list.forEach((item) => {
      if (!isFolderItem(item)) return;
      const folderPath = normalizeRelPath(item.relative_path || item.name || '').toLowerCase();
      if (!folderPath) return;
      const traits = new Set(item._selfTraits || []);
      const prefix = `${folderPath}/`;
      list.forEach((child) => {
        if (child === item) return;
        const childPath = normalizeRelPath(child.relative_path || child.name || '').toLowerCase();
        if (!childPath.startsWith(prefix)) return;
        (child._selfTraits || []).forEach((trait) => traits.add(trait));
      });
      item._traits = traits;
    });
    return list;
  };

  const itemHasTrait = (item, trait) => {
    const key = String(trait || '')
      .toLowerCase()
      .replace(/^\.+/, '');
    if (!key) return true;
    if (key === 'folder' || key === 'folders') return isFolderItem(item);
    if (key === 'file' || key === 'files') return !isFolderItem(item);
    if (item._ext === key) return true;
    const traits = item._traits instanceof Set ? item._traits : null;
    return !!(traits && traits.has(key));
  };

  const itemHasTagNeedle = (item, needle) => {
    const want = String(needle || '')
      .trim()
      .toLowerCase();
    if (!want) return true;
    const hay = String(item?._tagHay || '');
    if (!hay) return false;
    return hay.split('\n').some((line) => line === want || line.includes(want));
  };

  const cheapItemMatch = (item, words, mode) => {
    const hay = item._hay || '';
    if (!words.length) return { matched: true, score: 100, kind: 'exact' };
    if (!dialogHayHasWords(hay, words, mode)) return { matched: false, score: 0, kind: 'none' };
    const name = String(item?.name || '').toLowerCase();
    const nameHit =
      mode === 'or' ? words.some((word) => name.includes(word)) : words.every((word) => name.includes(word));
    if (nameHit) {
      const exact = words.some((word) => name === word);
      return {
        matched: true,
        score: exact ? 100 : 94,
        kind: exact ? 'exact' : 'contains',
        source: 'Name',
        snippet: item.name,
      };
    }
    const tagHay = String(item?._tagHay || '');
    if (tagHay && dialogHayHasWords(tagHay, words, mode)) {
      const snippet =
        tagHay
          .split('\n')
          .find((line) => words.some((word) => line.includes(String(word || '').toLowerCase()))) || '';
      return { matched: true, score: 90, kind: 'contains', source: 'Tag', snippet };
    }
    return { matched: true, score: 86, kind: 'contains', source: 'Path' };
  };

  /** @returns {{ matched: boolean, score: number, kind?: string }} */
  const scoreItemQuery = (item, query, options = {}) => {
    const prefs = { ...readSearchPrefs(), ...options };
    const mode = prefs.wordMode === 'or' ? 'or' : 'and';
    const fuzzyOn = !!prefs.fuzzy;
    const parsed = prefs.parsed || parseDialogQuery(query);

    if (!dialogQueryIsActive(parsed)) return { matched: true, score: 100, kind: 'exact' };
    if (!item?._searchReady) prepareSearchItem(item);

    const hay = item._hay || '';
    if (parsed.excludes?.length && parsed.excludes.some((token) => hay.includes(token))) {
      return { matched: false, score: 0, kind: 'none' };
    }
    if (parsed.phrases?.length && !parsed.phrases.every((phrase) => hay.includes(phrase))) {
      return { matched: false, score: 0, kind: 'none' };
    }
    if (parsed.paths?.length) {
      const pathHay = item._pathHay || String(item?.relative_path || '').toLowerCase();
      if (!parsed.paths.every((path) => pathHay.includes(path))) {
        return { matched: false, score: 0, kind: 'none' };
      }
    }
    if (parsed.extensions?.length && !parsed.extensions.every((ext) => itemHasTrait(item, ext))) {
      return { matched: false, score: 0, kind: 'none' };
    }
    if (parsed.types?.length && !parsed.types.every((type) => itemHasTrait(item, type))) {
      return { matched: false, score: 0, kind: 'none' };
    }
    if (parsed.has?.length && !parsed.has.every((trait) => itemHasTrait(item, trait))) {
      return { matched: false, score: 0, kind: 'none' };
    }
    if (parsed.lacks?.length && !parsed.lacks.every((trait) => !itemHasTrait(item, trait))) {
      return { matched: false, score: 0, kind: 'none' };
    }
    if (parsed.person) {
      const needle = dialogResolveMeAlias(parsed.person);
      const people = `${item.modified_by || ''}\n${item.person || ''}`.toLowerCase();
      if (!people.includes(needle)) return { matched: false, score: 0, kind: 'none' };
    }
    if (parsed.modifiedBy) {
      const needle = dialogResolveMeAlias(parsed.modifiedBy);
      if (!String(item.modified_by || '')
        .toLowerCase()
        .includes(needle)) {
        return { matched: false, score: 0, kind: 'none' };
      }
    }
    if (parsed.createdBy) {
      const needle = dialogResolveMeAlias(parsed.createdBy);
      if (!String(item.person || '')
        .toLowerCase()
        .includes(needle)) {
        return { matched: false, score: 0, kind: 'none' };
      }
    }
    if (parsed.tags?.length && !parsed.tags.every((tag) => itemHasTagNeedle(item, tag))) {
      return { matched: false, score: 0, kind: 'none' };
    }

    const scoreWords = parsed.words || [];
    if (!scoreWords.length) {
      return {
        matched: true,
        score: parsed.phrases?.length ? 96 : 88,
        kind: parsed.phrases?.length ? 'exact' : 'contains',
        source: parsed.tags?.length ? 'Tag' : parsed.paths?.length ? 'Path' : 'Filter',
        snippet: parsed.phrases?.[0] || parsed.tags?.[0] || parsed.paths?.[0] || '',
      };
    }

    if (fuzzyOn && Fuzzy?.scoreLabeledFieldsAgainstWords) {
      if (mode === 'and') {
        for (let i = 0; i < scoreWords.length; i++) {
          const word = String(scoreWords[i] || '');
          if (word.length < 3 && !hay.includes(word)) {
            return { matched: false, score: 0, kind: 'none' };
          }
        }
      }
      return Fuzzy.scoreLabeledFieldsAgainstWords(itemSearchFields(item), scoreWords, mode, true);
    }
    return cheapItemMatch(item, scoreWords, mode);
  };

  const matchesQuery = (item, query, options = {}) => scoreItemQuery(item, query, options).matched;

  const countTreeRowsIfExpanded = (nodes) => {
    let count = 0;
    const walk = (list) => {
      (list || []).forEach((node) => {
        count += 1;
        if (node.children?.length) walk(node.children);
      });
    };
    walk(nodes);
    return count;
  };

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
        label: 'Updated',
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

  const collectTreeFolderPaths = (nodes, acc = []) => {
    (nodes || []).forEach((node) => {
      if (!node?.children?.length) return;
      const path = String(node.path || '').toLowerCase();
      if (path) acc.push(path);
      collectTreeFolderPaths(node.children, acc);
    });
    return acc;
  };

  const treeRevealKey = (query, kind, ext, prefs) =>
    `${String(query || '').trim()}|${kind || 'all'}|${(Array.isArray(ext) ? ext : []).slice().sort().join(',')}|${prefs?.wordMode || ''}|${prefs?.fuzzy ? '1' : '0'}`;

  const prefersReducedMotion = () =>
    typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const playWorkspaceDialogEnter = (dialog) => {
    if (!dialog || prefersReducedMotion()) return;
    dialog.classList.remove('is-entering', 'is-leaving');
    void dialog.offsetWidth;
    dialog.classList.add('is-entering');
    const surface = dialog.querySelector(':scope > .response-dialog-form');
    const done = (event) => {
      if (
        (event.target !== dialog && event.target !== surface) ||
        event.animationName === 'sp-workspace-backdrop-in'
      ) {
        return;
      }
      dialog.classList.remove('is-entering');
      dialog.removeEventListener('animationend', done);
    };
    dialog.addEventListener('animationend', done);
    const durationMs =
      Number.parseFloat(getComputedStyle(dialog).getPropertyValue('--sp-user-animation-duration')) || 580;
    window.setTimeout(() => {
      dialog.classList.remove('is-entering');
      dialog.removeEventListener('animationend', done);
    }, durationMs + 180);
  };

  const workspaceExitDurationMs = (dialog) => {
    const raw = Number.parseFloat(
      getComputedStyle(dialog).getPropertyValue('--sp-dialog-exit-duration')
    );
    return Number.isFinite(raw) && raw > 0 ? raw : 240;
  };

  const playWorkspaceDialogLeave = (dialog) =>
    new Promise((resolve) => {
      if (!dialog?.open) {
        resolve();
        return;
      }
      const animation = dialog.getAttribute('data-list-animation') || '';
      if (prefersReducedMotion() || animation === 'none') {
        dialog.classList.remove('is-leaving');
        resolve();
        return;
      }
      dialog.classList.remove('is-entering', 'is-leaving');
      void dialog.offsetWidth;
      dialog.classList.add('is-leaving');
      let settled = false;
      const surface = dialog.querySelector(':scope > .response-dialog-form');
      const settle = () => {
        if (settled) return;
        settled = true;
        dialog.removeEventListener('animationend', onEnd);
        resolve();
      };
      const onEnd = (event) => {
        if (event.target !== dialog && event.target !== surface) return;
        if (String(event.animationName || '').includes('backdrop')) return;
        settle();
      };
      dialog.addEventListener('animationend', onEnd);
      window.setTimeout(settle, workspaceExitDurationMs(dialog) + 80);
    });

  const snapshotTreeRowTops = (tbody) => {
    const map = new Map();
    tbody?.querySelectorAll('tr.sp-dialog-row[data-tree-path]').forEach((row) => {
      const path = (row.getAttribute('data-tree-path') || '').toLowerCase();
      if (path) map.set(path, row.getBoundingClientRect().top);
    });
    return map;
  };

  const settleTreeRows = (tbody, previousTops) => {
    if (!tbody || prefersReducedMotion()) return;
    const rows = [...tbody.querySelectorAll('tr.sp-dialog-row[data-tree-path]')];
    if (rows.length === 0) return;

    window.requestAnimationFrame(() => {
      const shifting = [];
      let appearIndex = 0;

      rows.forEach((row) => {
        const path = (row.getAttribute('data-tree-path') || '').toLowerCase();
        const prevTop = previousTops.get(path);
        row.classList.remove('is-settling', 'is-collapsing');
        row.style.removeProperty('--sp-settle-delay');

        if (prevTop == null) {
          row.classList.add('is-settling');
          row.style.setProperty('--sp-settle-delay', `${Math.min(appearIndex * 14, 180)}ms`);
          appearIndex += 1;
          const clear = (event) => {
            if (event.target !== row && event.target.parentElement !== row) return;
            row.classList.remove('is-settling');
            row.style.removeProperty('--sp-settle-delay');
          };
          row.addEventListener('animationend', clear, { once: true });
          return;
        }

        const delta = prevTop - row.getBoundingClientRect().top;
        if (Math.abs(delta) < 1.5) return;
        shifting.push({ row, delta });
      });

      if (shifting.length === 0) return;

      shifting.forEach(({ row, delta }) => {
        row.classList.add('is-collapsing');
        row.querySelectorAll('td').forEach((td) => {
          td.style.transition = 'none';
          td.style.transform = `translateY(${delta}px)`;
        });
      });
      void tbody.offsetHeight;
      shifting.forEach(({ row }) => {
        const cells = row.querySelectorAll('td');
        cells.forEach((td) => {
          td.style.transition = 'transform 0.32s cubic-bezier(0.22, 1, 0.36, 1)';
          td.style.transform = 'none';
        });
        const clear = (event) => {
          if (event.target?.tagName !== 'TD') return;
          row.classList.remove('is-collapsing');
          cells.forEach((td) => {
            td.style.transition = '';
            td.style.transform = '';
          });
        };
        row.addEventListener('transitionend', clear, { once: true });
      });
    });
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
    const parsed = options.parsed || parseDialogQuery(query);
    const matchOpts = { ...options, parsed };
    const out = [];
    nodes.forEach((node) => {
      const filteredChildren = filterTree(node.children, kind, ext, query, matchOpts);
      const selfMatch = passesKindExt(node.item, kind, ext) && matchesQuery(node.item, query, matchOpts);
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

  const flattenFiltered = (items, kind, ext, query, options = {}) => {
    const parsed = options.parsed || parseDialogQuery(query);
    const matchOpts = { ...options, parsed };
    return (items || []).filter((item) => passesKindExt(item, kind, ext) && matchesQuery(item, query, matchOpts));
  };

  const SORT_KEYS = new Set(['name', 'type', 'size', 'modified', 'created', 'modified_by', 'created_by']);

  const itemTypeSortKey = (item) => {
    if (isFolderItem(item)) return 'folder';
    return String(resolveMeta(item)?.label || fileExtension(item?.name) || 'file').toLowerCase();
  };

  const itemSortValue = (item, key) => {
    switch (key) {
      case 'type':
        return itemTypeSortKey(item);
      case 'size':
        return Number(item?.size_bytes) || 0;
      case 'modified': {
        const date = parseItemDate(item?.last_modified) || parseItemDate(item?.date_created);
        return date ? date.getTime() : 0;
      }
      case 'created': {
        const date = parseItemDate(item?.date_created);
        return date ? date.getTime() : 0;
      }
      case 'modified_by':
        return String(item?.modified_by || '').trim().toLowerCase();
      case 'created_by':
        return String(item?.person || '').trim().toLowerCase();
      case 'name':
      default:
        return String(item?.name || '').trim().toLowerCase();
    }
  };

  const compareItemsBySort = (a, b, key, dir) => {
    const sortKey = SORT_KEYS.has(key) ? key : 'name';
    const direction = dir === 'desc' ? -1 : 1;

    // Name sort always keeps folders before files; direction only flips A–Z vs Z–A.
    if (sortKey === 'name') {
      const af = isFolderItem(a) ? 0 : 1;
      const bf = isFolderItem(b) ? 0 : 1;
      if (af !== bf) return af - bf;
    }

    const av = itemSortValue(a, sortKey);
    const bv = itemSortValue(b, sortKey);
    let cmp = 0;
    if (typeof av === 'number' && typeof bv === 'number') {
      cmp = av - bv;
    } else {
      cmp = String(av).localeCompare(String(bv), undefined, { sensitivity: 'base', numeric: true });
    }
    if (cmp === 0) {
      cmp = String(a?.name || '').localeCompare(String(b?.name || ''), undefined, {
        sensitivity: 'base',
        numeric: true,
      });
    }
    if (cmp === 0) {
      const af = isFolderItem(a) ? 0 : 1;
      const bf = isFolderItem(b) ? 0 : 1;
      cmp = af - bf;
    }
    return cmp * direction;
  };

  const sortTreeNodesBy = (nodes, key, dir) => {
    const list = Array.isArray(nodes) ? [...nodes] : [];
    list.sort((a, b) => compareItemsBySort(a.item, b.item, key, dir));
    return list.map((node) => ({
      ...node,
      children: isFolderItem(node.item) ? sortTreeNodesBy(node.children || [], key, dir) : [],
    }));
  };

  const sortItemsBy = (items, key, dir) => {
    const list = Array.isArray(items) ? [...items] : [];
    list.sort((a, b) => compareItemsBySort(a, b, key, dir));
    return list;
  };

  /**
   * Wire AND/OR + Fuzzy toggles inside a dialog search panel.
   * Prefs are shared with the main SharePoint catalog search.
   */
  const bindDialogSearchModes = (root, onChange) => {
    if (!root) return () => readSearchPrefs();

    const wordModeGroup = root.querySelector('.sp-dialog-word-mode');
    const fuzzyBtn = root.querySelector('.sp-dialog-fuzzy');
    const deepBtn = root.querySelector('.sp-dialog-deep');

    const syncUi = (query = '') => {
      const prefs = readSearchPrefs();
      const parsed = parseDialogQuery(query);
      const words = parsed.words || [];
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
      if (deepBtn) {
        deepBtn.classList.toggle('is-active', prefs.deep);
        deepBtn.setAttribute('aria-pressed', prefs.deep ? 'true' : 'false');
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

    deepBtn?.addEventListener('click', () => {
      const prefs = readSearchPrefs();
      prefs.deep = !prefs.deep;
      writeSearchPrefs(prefs);
      syncUi(root.querySelector('input[type="search"]')?.value || '');
      onChange?.();
    });

    syncUi('');
    return syncUi;
  };

  const catalogSavedStorageKey = () => {
    const root = document.getElementById('sharepoint-search');
    return root?.getAttribute('data-public') === '1'
      ? 'riskregister_sp_public_search_saved'
      : 'riskregister_sp_search_saved';
  };

  const readCatalogSavedSearches = () => {
    try {
      const raw = JSON.parse(localStorage.getItem(catalogSavedStorageKey()) || '[]');
      if (!Array.isArray(raw)) return [];
      return raw.filter((item) => item && typeof item === 'object' && String(item.name || '').trim());
    } catch {
      return [];
    }
  };

  const savedSearchDialogQuery = (item) => {
    if (!item || typeof item !== 'object') return '';
    const snap = item.state && typeof item.state === 'object' ? item.state : {};
    return [snap.query || item.query, snap.refine]
      .map((part) => String(part || '').trim())
      .filter(Boolean)
      .join(' ')
      .trim();
  };

  const currentCatalogDialogQuery = () => {
    const fromApi = window.RiskRegisterSharePoint?.getCatalogDialogQuery;
    if (typeof fromApi === 'function') {
      return String(fromApi() || '').trim();
    }
    const live = document.getElementById('sharepoint-search-input');
    const refine = document.getElementById('sharepoint-refine-input');
    return [live?.value, refine?.value]
      .map((part) => String(part || '').trim())
      .filter(Boolean)
      .join(' ')
      .trim();
  };

  const dialogPresetPainters = [];
  const registerDialogPresetPainter = (paint) => {
    if (typeof paint !== 'function') return;
    dialogPresetPainters.push(paint);
    paint();
  };
  const paintDialogSavedPresets = () => {
    dialogPresetPainters.forEach((paint) => {
      try {
        paint();
      } catch {
        /* ignore */
      }
    });
  };

  const bindDialogSavedPresets = (root, options = {}) => {
    const chipsEl = typeof root === 'string' ? document.getElementById(root) : root;
    const wrap = chipsEl?.closest('.sharepoint-dialog-saved-searches') || chipsEl;
    if (!chipsEl) return () => {};

    const getActiveQuery = typeof options.getActiveQuery === 'function' ? options.getActiveQuery : () => '';
    const onApply = typeof options.onApply === 'function' ? options.onApply : () => {};

    const paint = () => {
      const current = currentCatalogDialogQuery();
      const active = String(getActiveQuery() || '').trim();
      const items = readCatalogSavedSearches().filter((item) => savedSearchDialogQuery(item));
      const chips = [];
      if (current) {
        const currentActive = current === active;
        chips.push(
          `<span class="sharepoint-recent-chip-wrap${currentActive ? ' is-active' : ''}" role="listitem">
            <button type="button" class="sharepoint-recent-chip" data-dialog-saved-id="__current__" title="Use the live Find query from the main search: ${escapeHtml(current)}">Current</button>
          </span>`
        );
      }
      items.forEach((item) => {
        const query = savedSearchDialogQuery(item);
        const isDash = item.kind === 'dashboard';
        const label = `${isDash ? '📊 ' : ''}${item.name}`;
        const isActive = query === active;
        chips.push(
          `<span class="sharepoint-recent-chip-wrap${isActive ? ' is-active' : ''}${isDash ? ' is-dashboard-snap' : ''}" role="listitem">
            <button type="button" class="sharepoint-recent-chip${isDash ? ' sp-saved-dash-chip' : ''}" data-dialog-saved-id="${escapeHtml(String(item.id))}" title="${escapeHtml(query)}">${escapeHtml(label)}</button>
          </span>`
        );
      });
      if (!chips.length) {
        if (wrap) {
          wrap.hidden = true;
          wrap.classList.add('is-hidden');
        }
        chipsEl.innerHTML = '';
        return;
      }
      if (wrap) {
        wrap.hidden = false;
        wrap.classList.remove('is-hidden');
      }
      chipsEl.innerHTML = chips.join('');
    };

    if (!chipsEl.dataset.dialogPresetsBound) {
      chipsEl.dataset.dialogPresetsBound = '1';
      chipsEl.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-dialog-saved-id]');
        if (!chip || !chipsEl.contains(chip)) return;
        event.preventDefault();
        const id = chip.getAttribute('data-dialog-saved-id') || '';
        let query = '';
        if (id === '__current__') {
          query = currentCatalogDialogQuery();
        } else {
          const item = readCatalogSavedSearches().find((entry) => String(entry.id) === String(id));
          query = savedSearchDialogQuery(item);
        }
        onApply(query);
        paintDialogSavedPresets();
      });
      registerDialogPresetPainter(paint);
    } else {
      paint();
    }
    return paint;
  };

  const formatSearchModeBits = (query, prefs) => {
    const bits = [];
    const parsed = parseDialogQuery(query);
    const words = parsed.words || [];
    if (words.length > 1) bits.push(String(prefs.wordMode || 'and').toUpperCase());
    if (prefs.fuzzy) bits.push('Fuzzy');
    if (dialogQueryIsActive(parsed)) bits.push(`“${String(query).trim()}”`);
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

  const qrButtonHtml = (url, ariaName = 'link', meta = {}) => {
    const href = String(url || '').trim();
    if (!href) return '';
    const sourceKey = String(meta.sourceKey || '').trim();
    const catalog = String(meta.catalog || '').trim();
    const sourceAttr = sourceKey ? ` data-qr-source-key="${escapeHtml(sourceKey)}"` : '';
    const catalogAttr = catalog ? ` data-qr-catalog="${escapeHtml(catalog)}"` : '';
    return `<button type="button" class="button ghost-light sp-qr-btn" data-qr-url="${escapeHtml(href)}" data-qr-label="${escapeHtml(ariaName)}"${sourceAttr}${catalogAttr} title="Show QR code for mobile scan" aria-label="Show QR code for ${escapeHtml(ariaName)}" onclick="event.stopPropagation()">QR</button>`;
  };

  const initQrDialog = () => {
    const dialog = document.getElementById('sharepoint-qr-dialog');
    if (!dialog) return null;
    const frame = document.getElementById('sharepoint-qr-frame');
    const openLink = document.getElementById('sharepoint-qr-open');
    const copyBtn = document.getElementById('sharepoint-qr-copy');
    const shareBtn = document.getElementById('sharepoint-qr-share');
    const printBtn = document.getElementById('sharepoint-qr-print');
    const closeBtn = document.getElementById('sharepoint-qr-dialog-close');
    const doneBtn = document.getElementById('sharepoint-qr-done');
    const subEl = document.getElementById('sharepoint-qr-dialog-sub');
    const headBrandEl = document.getElementById('sharepoint-qr-head-brand');
    const catalogWrap = document.getElementById('sharepoint-qr-catalog-wrap');
    const sizeEl = document.getElementById('sharepoint-qr-size');
    let currentUrl = '';
    let currentLabel = '';
    let currentCatalog = '';
    let currentSourceKey = '';

    const close = () => {
      if (typeof dialog.close === 'function' && dialog.open) dialog.close();
    };

    const flashIconBtn = (btn, ok) => {
      if (!btn) return;
      btn.classList.toggle('is-ok', !!ok);
      btn.classList.toggle('is-bad', !ok);
      window.setTimeout(() => {
        btn.classList.remove('is-ok', 'is-bad');
      }, 1200);
    };

    const brandTitle = () =>
      String(dialog.getAttribute('data-brand-title') || 'AdventHealth').trim() || 'AdventHealth';

    const logoUrl = () => String(dialog.getAttribute('data-brand-logo') || '').trim();
    const faviconUrl = () => String(dialog.getAttribute('data-brand-favicon') || '').trim();

    const utf8Bytes = (text) => {
      try {
        return new TextEncoder().encode(String(text || '')).length;
      } catch {
        return String(text || '').length;
      }
    };

    const formatKb = (bytes) => {
      const n = Math.max(0, Number(bytes) || 0);
      if (n < 1024) return `${n} B`;
      const kb = n / 1024;
      return `${kb < 10 ? kb.toFixed(2) : kb.toFixed(1)} KB`;
    };

    const headBrandHtml = () => {
      // Logo stays in the header only.
      const image = logoUrl() || faviconUrl();
      const title = brandTitle();
      if (image) {
        return `<img class="sharepoint-qr-org-logo" src="${escapeHtml(image)}" alt="${escapeHtml(title)}" title="${escapeHtml(title)}">`;
      }
      return `<span class="sharepoint-qr-org-fallback" title="${escapeHtml(title)}" aria-hidden="true">❤️</span>`;
    };

    const brandCenterHtml = () => {
      // Center mark uses favicon only (not the wide logo).
      const image = faviconUrl();
      const title = brandTitle();
      if (image) {
        return `<span class="sharepoint-qr-brand" aria-hidden="true"><img class="sharepoint-qr-favicon" src="${escapeHtml(image)}" alt="" width="28" height="28" decoding="async"></span>`;
      }
      return `<span class="sharepoint-qr-brand sharepoint-qr-brand--fallback" aria-hidden="true" title="${escapeHtml(title)}">❤️</span>`;
    };

    const fitCenterFavicon = () => {
      const img = frame?.querySelector('.sharepoint-qr-favicon');
      if (!img) return;
      const apply = () => {
        const w = img.naturalWidth || 0;
        const h = img.naturalHeight || 0;
        if (!w || !h) return;
        const ratio = w / h;
        img.classList.toggle('is-wide', ratio > 1.35);
        img.classList.toggle('is-tall', ratio < 0.75);
        img.classList.toggle('is-square', ratio >= 0.75 && ratio <= 1.35);
      };
      if (img.complete && img.naturalWidth) apply();
      else img.addEventListener('load', apply, { once: true });
    };

    const syncQrSizeFooter = (svgMarkup, url) => {
      if (!sizeEl) return;
      const qrBytes = utf8Bytes(svgMarkup);
      const payloadBytes = utf8Bytes(url);
      if (!qrBytes) {
        sizeEl.hidden = true;
        sizeEl.textContent = '';
        return;
      }
      sizeEl.hidden = false;
      sizeEl.textContent = `QR size ${formatKb(qrBytes)} · link payload ${formatKb(payloadBytes)}`;
      sizeEl.title = `Generated QR markup: ${qrBytes} bytes. Encoded URL: ${payloadBytes} bytes.`;
    };

    const syncCatalogBadge = () => {
      if (!catalogWrap) return;
      const badge = catalogBadgeHtml(currentCatalog, currentSourceKey);
      if (!badge) {
        catalogWrap.hidden = true;
        catalogWrap.innerHTML = '';
        return;
      }
      catalogWrap.hidden = false;
      catalogWrap.innerHTML = badge;
    };

    if (headBrandEl) {
      headBrandEl.innerHTML = headBrandHtml();
    }

    closeBtn?.addEventListener('click', close);
    doneBtn?.addEventListener('click', (event) => {
      event.preventDefault();
      close();
    });
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) close();
    });

    copyBtn?.addEventListener('click', async (event) => {
      event.preventDefault();
      event.stopPropagation();
      const url = copyBtn.getAttribute('data-copy-url') || currentUrl;
      if (!url) return;
      const ok = await copyTextToClipboard(url);
      flashIconBtn(copyBtn, ok);
    });

    const canNativeShare = typeof navigator.share === 'function';
    if (shareBtn) {
      shareBtn.title = canNativeShare
        ? 'Share this link with another app'
        : 'Share is unavailable here — copies the link instead';
      shareBtn.addEventListener('click', async (event) => {
        event.preventDefault();
        if (!currentUrl) return;
        const title = currentLabel || brandTitle() || 'SharePoint link';
        try {
          if (canNativeShare) {
            await navigator.share({ title, text: title, url: currentUrl });
            flashIconBtn(shareBtn, true);
            return;
          }
        } catch (error) {
          if (error?.name === 'AbortError') return;
        }
        const ok = await copyTextToClipboard(currentUrl);
        flashIconBtn(shareBtn, ok);
      });
    }

    printBtn?.addEventListener('click', (event) => {
      event.preventDefault();
      if (!frame || !frame.querySelector('.sharepoint-qr-mark, svg')) return;
      const title = currentLabel || 'QR Code';

      const absoluteUrl = (rel) => {
        try {
          return new URL(String(rel || ''), window.location.href).href;
        } catch {
          return String(rel || '');
        }
      };

      const dataUrlForImg = (img) => {
        if (!(img instanceof HTMLImageElement)) return '';
        if (!img.complete || !img.naturalWidth) return img.currentSrc || img.src || '';
        try {
          const canvas = document.createElement('canvas');
          canvas.width = img.naturalWidth;
          canvas.height = img.naturalHeight;
          const ctx = canvas.getContext('2d');
          if (!ctx) return img.currentSrc || img.src || '';
          ctx.drawImage(img, 0, 0);
          return canvas.toDataURL('image/png');
        } catch {
          return absoluteUrl(img.currentSrc || img.src || '');
        }
      };

      const withEmbeddedImages = (root) => {
        if (!root) return '';
        const clone = root.cloneNode(true);
        clone.querySelectorAll('img').forEach((img) => {
          const dataUrl = dataUrlForImg(img);
          if (dataUrl) img.setAttribute('src', dataUrl);
        });
        return clone.innerHTML;
      };

      const mark = withEmbeddedImages(frame);
      const badge = catalogWrap && !catalogWrap.hidden ? catalogWrap.innerHTML : '';
      const org = withEmbeddedImages(headBrandEl);
      const sizeNote = sizeEl && !sizeEl.hidden ? sizeEl.textContent : '';
      const html = `<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(title)} · QR</title>
        <style>
          body{font-family:system-ui,sans-serif;margin:24px;text-align:center;color:#0f172a}
          .org{display:flex;justify-content:center;margin-bottom:10px}
          .org img{max-height:42px;width:auto;object-fit:contain}
          .org .sharepoint-qr-org-fallback{font-size:1.6rem}
          h1{font-size:16px;margin:0 0 8px}
          .badge{margin:0 0 12px}
          .sp-catalog-badge{display:inline-flex;align-items:center;padding:0.12rem 0.5rem;border-radius:999px;font-size:0.72rem;font-weight:700;color:#0f766e;background:rgba(15,118,110,.12);border:1px solid rgba(15,118,110,.28)}
          p{font-size:12px;color:#64748b;margin:0 0 16px}
          .size{font-size:11px;color:#94a3b8;margin:8px 0 0}
          .qr{display:inline-block;padding:16px;border:1px solid #e2e8f0;border-radius:12px}
          .sharepoint-qr-mark{position:relative;display:inline-grid;place-items:center}
          .sharepoint-qr-mark svg{width:260px;height:auto;display:block}
          .sharepoint-qr-brand{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:clamp(2.1rem,22%,3rem);aspect-ratio:1;display:grid;place-items:center;border-radius:6px;background:#fff;box-shadow:0 0 0 3px #fff;overflow:hidden;padding:0.18rem;box-sizing:border-box}
          .sharepoint-qr-brand img,.sharepoint-qr-favicon{width:100%;height:100%;object-fit:contain;display:block}
          .sharepoint-qr-brand--fallback{font-size:1rem;line-height:1;padding:0}
          @media print{body{margin:12px}}
        </style></head><body>
        <div class="org">${org}</div>
        <h1>${escapeHtml(title)}</h1>
        ${badge ? `<div class="badge">${badge}</div>` : ''}
        <p>Scan with your phone camera to open this link</p>
        <div class="qr">${mark}</div>
        ${sizeNote ? `<p class="size">${escapeHtml(sizeNote)}</p>` : ''}
        </body></html>`;

      // Hidden iframe avoids popup blockers. Do not use window.open(..., 'noopener') —
      // that returns null and the old print path never ran.
      const iframe = document.createElement('iframe');
      iframe.setAttribute('aria-hidden', 'true');
      iframe.setAttribute('title', 'Print QR code');
      iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;opacity:0;pointer-events:none;';
      document.body.appendChild(iframe);

      const doc = iframe.contentDocument || iframe.contentWindow?.document;
      const win = iframe.contentWindow;
      if (!doc || !win) {
        iframe.remove();
        flashIconBtn(printBtn, false);
        return;
      }

      doc.open();
      doc.write(html);
      doc.close();

      try {
        // Keep print() inside the click gesture so browsers allow the dialog.
        win.focus();
        win.print();
        flashIconBtn(printBtn, true);
      } catch {
        flashIconBtn(printBtn, false);
      }

      const removeIframe = () => {
        try {
          iframe.remove();
        } catch {
          /* ignore */
        }
      };
      win.addEventListener?.('afterprint', removeIframe, { once: true });
      window.setTimeout(removeIframe, 60_000);
    });

    const renderQrSvg = (url) => {
      const makeQr = typeof window.qrcode === 'function' ? window.qrcode : null;
      if (!makeQr) {
        syncQrSizeFooter('', url);
        return '<p class="sharepoint-qr-error">QR library failed to load. Use Open or Copy instead.</p>';
      }
      try {
        if (makeQr.stringToBytesFuncs?.['UTF-8']) {
          makeQr.stringToBytes = makeQr.stringToBytesFuncs['UTF-8'];
        }
        // High error correction so a small center favicon still scans reliably.
        const qr = makeQr(0, 'H');
        qr.addData(url);
        qr.make();
        const svg = qr.createSvgTag({
          cellSize: 4,
          margin: 2,
          scalable: true,
          alt: currentLabel ? `QR code for ${currentLabel}` : 'QR code for SharePoint link',
          title: currentLabel ? `QR code for ${currentLabel}` : 'Scan to open SharePoint',
        });
        syncQrSizeFooter(svg, url);
        return `<div class="sharepoint-qr-mark">${svg}${brandCenterHtml()}</div>`;
      } catch (error) {
        syncQrSizeFooter('', url);
        return `<p class="sharepoint-qr-error">Could not build QR code (${escapeHtml(error?.message || 'unknown error')}).</p>`;
      }
    };

    const open = (url, meta = {}) => {
      const href = String(url || '').trim();
      if (!href || !frame) return;
      const options = typeof meta === 'string' ? { label: meta } : meta || {};
      currentUrl = href;
      currentLabel = String(options.label || '').trim();
      currentCatalog = String(options.catalog || '').trim();
      currentSourceKey = String(options.sourceKey || '').trim();
      if (headBrandEl) headBrandEl.innerHTML = headBrandHtml();
      if (subEl) {
        subEl.textContent = currentLabel || 'Scan with your phone camera to open this link.';
      }
      syncCatalogBadge();
      frame.innerHTML = renderQrSvg(href);
      fitCenterFavicon();
      if (openLink) openLink.href = href;
      if (copyBtn) {
        copyBtn.setAttribute('data-copy-url', href);
        copyBtn.classList.remove('is-ok', 'is-bad');
      }
      shareBtn?.classList.remove('is-ok', 'is-bad');
      printBtn?.classList.remove('is-ok', 'is-bad');
      if (typeof dialog.showModal === 'function') dialog.showModal();
      else dialog.setAttribute('open', '');
    };

    return { open, close };
  };

  const qrDialog = initQrDialog();

  const bindQrButtons = (root = document) => {
    root?.querySelectorAll('.sp-qr-btn[data-qr-url]').forEach((btn) => {
      if (btn.dataset.qrBound === '1') return;
      btn.dataset.qrBound = '1';
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const url = btn.getAttribute('data-qr-url') || '';
        const row = btn.closest('[data-project-name], [data-source-key], tr');
        const label =
          btn.getAttribute('data-qr-label') ||
          btn.getAttribute('aria-label')?.replace(/^Show QR code for\s+/i, '') ||
          row?.querySelector('.sp-file-name')?.textContent ||
          '';
        const sourceKey =
          btn.getAttribute('data-qr-source-key') ||
          row?.getAttribute('data-source-key') ||
          '';
        const catalog =
          btn.getAttribute('data-qr-catalog') ||
          row?.querySelector('.sp-catalog-badge')?.textContent ||
          '';
        qrDialog?.open(url, { label, sourceKey, catalog });
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
    const archiveMark = item?.archived ? archivedBadgeHtml() : '';
    return `<div class="sp-tree-cell" style="--sp-depth:${depth}">${treeToggle}${link}${archiveMark}</div>`;
  };

  const itemArchiveBtnHtml = (item, ctx = {}) => {
    const name = String(item?.name || '');
    const path = String(item?.relative_path || '');
    const projectName = String(ctx.projectName || item?.project_name || '').trim();
    const sourceKey = String(ctx.sourceKey || item?.source_key || '').trim();
    const inherited = !!item?.archived && !item?.archived_direct;
    const archiveScope = String(item?.archive_scope || '');
    const isRoot = isRootProjectFolder(item, projectName);
    return archiveToggleHtml({
      archived: isRoot ? !!item?.archived : !!item?.archived_direct,
      disabled: !isRoot && inherited,
      title: isRoot
        ? item?.archived
          ? 'Show this project on the dashboard again'
          : 'Hide this project from the dashboard'
        : inherited
          ? archiveScope === 'source'
            ? 'Hidden with the catalog folder. Unarchive the folder first.'
            : archiveScope === 'project'
              ? 'Hidden with the project. Unarchive the project first.'
              : 'Hidden with a parent folder. Unarchive that folder first.'
          : item?.archived_direct
            ? 'Show this file or folder on the dashboard again'
            : 'Hide this file or folder from the dashboard',
      extraClass: 'sp-archive-item-btn',
      attrs: {
        'data-archive-scope': isRoot ? 'project' : 'item',
        'data-archive-path': path || name,
        'data-source-key': sourceKey,
        'data-project-name': projectName,
      },
    });
  };

  const itemActionsHtml = (item, qrMeta = {}, tagEdit = null) => {
    const name = String(item.name || '');
    const url = String(item.web_url || '');
    const path = String(item.relative_path || '');
    const copyBtn = url
      ? `<button type="button" class="sp-copy-link-btn" data-copy-url="${escapeHtml(url)}" data-label="📋" title="Copy SharePoint link" aria-label="Copy link for ${escapeHtml(name)}">📋</button>`
      : '';
    const qrBtn = qrButtonHtml(url, name, qrMeta);
    const tagsHtml = tagChipsHtml(item?.tags || [], {
      editable: !!(tagEdit?.canEdit ?? catalogCanEditTags()),
      scope: 'item',
      path: path || name,
    });
    let addTagHtml = '';
    if (tagEdit?.canEdit) {
      const assigned = new Set(normalizeTagList(item?.tags).map((tag) => tag.id));
      const options = normalizeTagList(tagEdit.allTags)
        .filter((tag) => !assigned.has(tag.id))
        .map((tag) => `<option value="${tag.id}">${escapeHtml(tag.label)}</option>`)
        .join('');
      if (options) {
        addTagHtml = `<label class="sp-item-tag-add"><select class="sp-tag-select sp-tag-select--compact" aria-label="Add tag to item"><option value="">+ Tag</option>${options}</select></label>`;
      }
    }
    return `<div class="sp-row-actions">${copyBtn}${qrBtn}${tagsHtml}${addTagHtml}</div>`;
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
    let folders = Number(project?.folder_count || 0);
    let files = Number(project?.file_count || 0);
    if (!catalogShowArchived()) {
      folders = Math.max(0, folders - Number(project?.archived_folder_count || 0));
      files = Math.max(0, files - Number(project?.archived_file_count || 0));
    }
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
    const meta = resolveProjectMeta(project);
    const typeLabel = meta.tone === 'folder' ? '📁 Folder' : meta.label;
    const tagsHtml = tagChipsHtml(project?.tags || [], { editable: false, scope: 'project' });
    const archiveMark = project?.archived ? archivedBadgeHtml(
      project?.archive_scope === 'source'
        ? 'This catalog folder is archived'
        : 'Hidden from the catalog dashboard'
    ) : '';
    const favMark = project?.favorited
      ? '<span class="sp-project-fav-mark" title="Favorite" aria-label="Favorite">★</span>'
      : '';
    return `<div class="sp-project-cell">
      <div class="sp-tree-cell">
        <button type="button" class="sharepoint-project-open sp-file-link" data-project-name="${escapeHtml(name)}" data-source-key="${escapeHtml(sourceKey)}" data-open-query="${escapeHtml(openQuery)}">
          <span class="sp-file-icon sp-file-icon--${escapeHtml(meta.tone)}" aria-hidden="true">${meta.emoji}</span>
          <span class="sp-file-copy">
            <span class="sp-file-name">${escapeHtml(name)}${favMark}</span>
          </span>
        </button>
      </div>
      <div class="sp-project-meta-line">
        <span class="sp-type-badge sp-type-badge--${escapeHtml(meta.tone)}">${escapeHtml(typeLabel)}</span>
        ${catalogBadgeHtml(sourceTitle, sourceKey)}
        ${archiveMark}
      </div>
      ${tagsHtml}
      ${extraHtml}
    </div>`;
  };

  const dialogItemCellsHtml = (item, depth, toggle, qrMeta = {}) => {
    const person = String(item?.person || '').trim();
    const modifiedBy = String(item?.modified_by || '').trim();
    return `<td data-col="name">${nameCellHtml(item, depth, toggle)}</td>
      <td data-col="type">${typeBadgeHtml(item)}</td>
      <td data-col="size" class="sp-meta-cell sp-size-cell">${escapeHtml(formatSize(item?.size_bytes))}</td>
      <td data-col="modified" class="sp-meta-cell">${escapeHtml(formatModified(item?.last_modified))}</td>
      <td data-col="created" class="sp-meta-cell">${escapeHtml(formatModified(item?.date_created))}</td>
      <td data-col="modified_by" class="sp-meta-cell">${personCellHtml(modifiedBy, '👤')}</td>
      <td data-col="created_by" class="sp-meta-cell">${personCellHtml(person, '🙋')}</td>`;
  };

  const dialogActionsCellHtml = (item, qrMeta = {}, tagEdit = null) =>
    `<td data-col="actions">${itemActionsHtml(item, qrMeta, tagEdit)}</td>`;

  const dialogArchiveCellHtml = (item, ctx = {}) =>
    `<td data-col="archive">${itemArchiveBtnHtml(item, ctx)}</td>`;

  /* ---- Project detail dialog ---- */
  const initDialog = () => {
    const dialog = document.getElementById('sharepoint-project-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return null;
    bindWorkspaceDialog(dialog);

    const titleEl = document.getElementById('sharepoint-project-dialog-title');
    const subEl = document.getElementById('sharepoint-project-dialog-sub');
    const statsEl = document.getElementById('sharepoint-project-dialog-stats');
    const tagsEl = document.getElementById('sharepoint-project-dialog-tags');
    const actionsEl = document.getElementById('sharepoint-project-dialog-actions');
    const rowsEl = document.getElementById('sharepoint-project-dialog-rows');
    const closeBtn = document.getElementById('sharepoint-project-dialog-close');
    const refreshBtn = document.getElementById('sharepoint-project-dialog-refresh');
    const searchWrap = document.getElementById('sharepoint-project-dialog-search-wrap');
    const searchInput = document.getElementById('sharepoint-project-dialog-search');
    const searchRun = document.getElementById('sharepoint-project-dialog-search-run');
    const searchPending = document.getElementById('sharepoint-project-dialog-search-pending');
    const searchClear = document.getElementById('sharepoint-project-dialog-search-clear');
    const searchMeta = document.getElementById('sharepoint-project-dialog-search-meta');
    const kindSelect = document.getElementById('sharepoint-project-dialog-kind');
    const extSelect = document.getElementById('sharepoint-project-dialog-ext');
    const tableEl = dialog.querySelector('.sharepoint-dialog-table');
    const headerRow = tableEl?.querySelector('thead tr');
    const columnsPicker = document.getElementById('sharepoint-project-columns-picker');
    /** @type {Set<string>} */
    let hiddenCols = readProjectHiddenCols();

    const visibleColspan = () => {
      let count = 1; // name always shown
      PROJECT_TOGGLE_COLS.forEach((col) => {
        if (!hiddenCols.has(col)) count += 1;
      });
      return Math.max(count, 1);
    };

    const emptyRowHtml = (message) =>
      `<tr><td colspan="${visibleColspan()}" class="sharepoint-dialog-empty">${message}</td></tr>`;

    const colResize = bindColumnResize(dialog, SEARCH_PREF.projectColWidths);

    const applyHiddenColsToDialog = () => {
      dialog.setAttribute('data-hidden-cols', [...hiddenCols].join(' '));
      columnsPicker?.querySelectorAll('input[data-col-toggle]').forEach((input) => {
        const col = input.getAttribute('data-col-toggle') || '';
        input.checked = !hiddenCols.has(col);
      });
      colResize.apply();
    };

    const setColumnHidden = (col, hide) => {
      if (!PROJECT_TOGGLE_COLS.includes(col)) return;
      if (hide) hiddenCols.add(col);
      else hiddenCols.delete(col);
      writeProjectHiddenCols(hiddenCols);
      applyHiddenColsToDialog();
      applyFilter();
    };

    let allItems = [];
    let preparedTreeAll = null;
    let preparedTreeActive = null;
    let committedQuery = '';
    let layout = 'tree';
    let sortKey = 'name';
    let sortDir = 'asc';
    const expanded = new Set();
    const selectedExts = new Set();
    let lastRevealKey = '';
    let syncSearchModes = () => readSearchPrefs();
    let currentName = '';
    let currentSourceKey = '';
    let currentCatalogTitle = '';
    let currentProjectTags = [];
    let dialogAllTags = [];
    let dialogCanEditTags = false;
    let projectBusy = false;
    let bindItemTagEditors = () => {};
    let bindItemArchiveButtons = () => {};
    let renderProjectTagsPanel = () => {};
    let saveTagsForTarget = async () => [];
    let bindTagEditor = () => {};

    const invalidatePreparedTree = () => {
      preparedTreeAll = null;
      preparedTreeActive = null;
    };

    const getPreparedTree = (visibleItems, showArchived) => {
      if (showArchived) {
        if (!preparedTreeAll) preparedTreeAll = buildTreeNodes(visibleItems);
        return preparedTreeAll;
      }
      if (!preparedTreeActive) preparedTreeActive = buildTreeNodes(visibleItems);
      return preparedTreeActive;
    };

    const syncPendingSearchHint = () => {
      const draft = searchInput?.value || '';
      const pending = draft !== committedQuery;
      searchWrap?.classList.toggle('has-pending-search', pending);
      searchRun?.classList.toggle('is-pending', pending);
      if (searchPending) searchPending.hidden = !pending;
      syncSearchModes(draft);
    };

    const clearActivityStats = () => {
      if (!statsEl) return;
      statsEl.hidden = true;
      statsEl.innerHTML = '';
    };

    const syncHeaderSort = () => {
      headerRow?.querySelectorAll('th.is-sortable').forEach((th) => {
        const key = th.getAttribute('data-sort') || '';
        const active = key === sortKey;
        th.classList.toggle('is-sorted-asc', active && sortDir === 'asc');
        th.classList.toggle('is-sorted-desc', active && sortDir === 'desc');
        th.setAttribute('aria-sort', active ? (sortDir === 'desc' ? 'descending' : 'ascending') : 'none');
        const btn = th.querySelector('.sp-dialog-sort-btn');
        if (btn) {
          btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        }
      });
    };

    const setSort = (key) => {
      const next = SORT_KEYS.has(key) ? key : 'name';
      if (sortKey === next) {
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
      } else {
        sortKey = next;
        sortDir = 'asc';
      }
      syncHeaderSort();
      applyFilter();
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

    const projectQrMeta = () => ({
      sourceKey: currentSourceKey || '',
      catalog: currentCatalogTitle || '',
    });

    const projectArchiveCtx = () => ({
      sourceKey: currentSourceKey || '',
      projectName: currentName || '',
    });

    const projectTagEdit = () => ({
      canEdit: dialogCanEditTags && catalogCanEditTags(),
      allTags: dialogAllTags,
    });

    const renderTreeRows = (nodes, depth, acc) => {
      const qrMeta = projectQrMeta();
      const archiveCtx = projectArchiveCtx();
      nodes.forEach((node) => {
        const path = node.path;
        const isFolder = isFolderItem(node.item);
        const hasKids = isFolder && node.children.length > 0;
        const isOpen = hasKids && expanded.has(path.toLowerCase());
        const toggle = hasKids
          ? `<button type="button" class="sp-tree-toggle" data-tree-path="${escapeHtml(path)}" aria-expanded="${isOpen ? 'true' : 'false'}">${isOpen ? '▼' : '▶'}</button>`
          : `<span class="sp-tree-toggle sp-tree-toggle--spacer" aria-hidden="true"></span>`;
        acc.push(`<tr class="sp-dialog-row${isFolder ? ' sp-dialog-row--folder' : ''}${node.item?.archived ? ' is-archived' : ''}${node.selfMatch === false && hasKids ? ' sp-tree-ancestor' : ''}" data-tree-path="${escapeHtml(path)}" data-tree-depth="${depth}">
          ${dialogItemCellsHtml(node.item, depth, toggle)}
          ${dialogActionsCellHtml(node.item, qrMeta, projectTagEdit())}
          ${dialogArchiveCellHtml(node.item, archiveCtx)}
        </tr>`);
        if (isOpen) renderTreeRows(node.children, depth + 1, acc);
      });
    };

    const applyFilter = () => {
      const query = committedQuery;
      const kind = kindSelect?.value || 'all';
      const ext = activeExtFilter();
      const prefs = syncSearchModes(searchInput?.value || query);
      const parsed = parseDialogQuery(query);
      const matchOpts = { ...prefs, parsed };
      syncLayoutButtons();
      syncPendingSearchHint();
      const showArchived = catalogShowArchived();
      const visibleItems = showArchived ? allItems : allItems.filter((item) => !item.archived);
      syncExtChips(searchWrap, visibleItems, selectedExts);
      syncHeaderSort();

      let shown = 0;
      if (layout === 'tree') {
        const baseTree = getPreparedTree(visibleItems, showArchived);
        const tree = sortTreeNodesBy(filterTree(baseTree, kind, ext, query, matchOpts), sortKey, sortDir);
        const key = treeRevealKey(query, kind, ext, prefs);
        if (key !== lastRevealKey) {
          lastRevealKey = key;
          if (String(query || '').trim() !== '' || ext.length > 0) {
            const fullCount = countTreeRowsIfExpanded(tree);
            if (fullCount <= DIALOG_TREE_EXPAND_ROW_CAP) {
              collectTreeFolderPaths(tree).forEach((path) => expanded.add(path));
            }
          }
        }
        const rows = [];
        renderTreeRows(tree, 0, rows);
        shown = rows.length;
        const skipSettle = String(query || '').trim() !== '' || rows.length > DIALOG_SETTLE_ROW_CAP;
        const previousTops = skipSettle ? null : snapshotTreeRowTops(rowsEl);
        rowsEl.innerHTML =
          rows.length > 0
            ? rows.join('')
            : emptyRowHtml(
                visibleItems.length === 0
                  ? showArchived
                    ? '🗂️ No files or folders found for this project.'
                    : '🗂️ No files or folders to show. Turn on Show archived to restore hidden items.'
                  : 'No matches for this view / filter. Try OR mode or Fuzzy.'
              );
        if (previousTops && rows.length > 0) settleTreeRows(rowsEl, previousTops);
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
        const filtered = sortItemsBy(flattenFiltered(visibleItems, kind, ext, query, matchOpts), sortKey, sortDir);
        shown = filtered.length;
        if (filtered.length === 0) {
          rowsEl.innerHTML = emptyRowHtml(
            visibleItems.length === 0
              ? showArchived
                ? '🗂️ No files or folders found for this project.'
                : '🗂️ No files or folders to show. Turn on Show archived to restore hidden items.'
              : 'No matches for this view / filter. Try OR mode or Fuzzy.'
          );
        } else {
          rowsEl.innerHTML = filtered
            .map((item) => {
              return `<tr class="sp-dialog-row${isFolderItem(item) ? ' sp-dialog-row--folder' : ''}${item?.archived ? ' is-archived' : ''}">
                ${dialogItemCellsHtml(item, 0, '')}
                ${dialogActionsCellHtml(item, projectQrMeta(), projectTagEdit())}
                ${dialogArchiveCellHtml(item, projectArchiveCtx())}
              </tr>`;
            })
            .join('');
        }
      }

      if (searchMeta) {
        const hiddenCount = allItems.filter((item) => item.archived).length;
        const bits = [`${shown} shown`, `of ${visibleItems.length}`];
        if (!showArchived && hiddenCount) bits.push(`${hiddenCount} archived hidden`);
        if (kind !== 'all') bits.push(kind === 'files' ? 'files only' : 'folders only');
        if (ext.length) bits.push(ext.map((value) => `.${value}`).join(' + '));
        if (layout === 'tree') bits.push('tree');
        bits.push(`sort ${sortKey} ${sortDir}`);
        bits.push(...formatSearchModeBits(query, prefs));
        searchMeta.textContent = bits.join(' · ');
      }
      if (searchClear) {
        searchClear.hidden = String(query || '').trim() === '' && selectedExts.size === 0 && String(searchInput?.value || '').trim() === '';
      }
      bindCopyLinkButtons(rowsEl);
      bindQrButtons(rowsEl);
      bindItemTagEditors();
      bindItemArchiveButtons();
    };

    const commitDialogSearch = () => {
      committedQuery = searchInput?.value || '';
      syncPendingSearchHint();
      applyFilter();
      paintDialogSavedPresets();
    };

    syncSearchModes = bindDialogSearchModes(searchWrap, applyFilter);

    searchInput?.addEventListener('input', () => {
      syncPendingSearchHint();
      if (searchClear) {
        searchClear.hidden =
          String(committedQuery || '').trim() === '' &&
          selectedExts.size === 0 &&
          String(searchInput?.value || '').trim() === '';
      }
    });
    searchInput?.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') return;
      if (event.isComposing || event.keyCode === 229) return;
      event.preventDefault();
      commitDialogSearch();
    });
    searchRun?.addEventListener('click', () => commitDialogSearch());
    bindDialogSavedPresets('sharepoint-project-dialog-saved-chips', {
      getActiveQuery: () => committedQuery,
      onApply: (query) => {
        if (searchInput) searchInput.value = query;
        committedQuery = query;
        syncPendingSearchHint();
        applyFilter();
      },
    });
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
      committedQuery = '';
      selectedExts.clear();
      syncExtSelectFromChips();
      syncPendingSearchHint();
      applyFilter();
      paintDialogSavedPresets();
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

    headerRow?.querySelectorAll('.sp-dialog-sort-btn[data-sort]').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        setSort(btn.getAttribute('data-sort') || 'name');
      });
    });
    syncHeaderSort();

    columnsPicker?.querySelectorAll('input[data-col-toggle]').forEach((input) => {
      input.addEventListener('change', () => {
        const col = input.getAttribute('data-col-toggle') || '';
        setColumnHidden(col, !input.checked);
      });
    });
    columnsPicker?.querySelector('.sp-compare-columns-menu')?.addEventListener('click', (event) => {
      event.stopPropagation();
    });
    bindColumnsMenuClose(columnsPicker);
    applyHiddenColsToDialog();

    renderProjectTagsPanel = () => {
      if (!tagsEl) return;
      const tags = normalizeTagList(currentProjectTags);
      const canEdit = dialogCanEditTags && catalogCanEditTags();
      if (!tags.length && !canEdit) {
        tagsEl.hidden = true;
        tagsEl.innerHTML = '';
        return;
      }
      tagsEl.hidden = false;
      const chips = tagChipsHtml(tags, { editable: canEdit, scope: 'project' });
      let editor = '';
      if (canEdit) {
        const options = normalizeTagList(dialogAllTags)
          .filter((tag) => !tags.some((assigned) => assigned.id === tag.id))
          .map((tag) => `<option value="${tag.id}">${escapeHtml(tag.label)}</option>`)
          .join('');
        editor = `<div class="sp-tag-editor">
          <label class="sp-tag-editor-add">
            <span class="sp-tag-editor-label">Tags</span>
            <select class="sp-tag-select" aria-label="Add project tag">
              <option value="">Add tag…</option>
              ${options}
            </select>
          </label>
          <label class="sp-tag-editor-create">
            <input type="text" class="sp-tag-create-input" maxlength="40" placeholder="New tag name" aria-label="Create new search tag">
            <button type="button" class="button ghost-light sp-tag-create-btn">Create</button>
          </label>
        </div>`;
      } else {
        editor = `<div class="sp-tag-editor"><span class="sp-tag-editor-label">Tags</span></div>`;
      }
      tagsEl.innerHTML = `<div class="sp-project-tags-row">${editor}${chips || '<div class="sp-tag-chips sp-tag-chips--empty"><span class="sp-tag-empty">No tags yet</span></div>'}</div>`;
      bindTagEditor(tagsEl, {
        scope: 'project',
        path: '',
        currentTags: () => currentProjectTags,
        setTags: (next) => {
          currentProjectTags = next;
          renderProjectTagsPanel();
        },
      });
    };

    saveTagsForTarget = async ({ scope, path, tagIds }) => {
      const action = scope === 'item' ? 'save_item_tags' : 'save_project_tags';
      const fields = {
        source_key: currentSourceKey,
        project_name: currentName,
        tag_ids: tagIds,
      };
      if (scope === 'item') fields.relative_path = path;
      const payload = await postCatalogAction(action, fields);
      return normalizeTagList(payload.tags);
    };

    bindTagEditor = (root, { scope, path, currentTags, setTags }) => {
      if (!root || !dialogCanEditTags) return;
      root.querySelectorAll('.sp-tag-remove').forEach((btn) => {
        btn.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopPropagation();
          const removeId = Number(btn.getAttribute('data-tag-id') || 0);
          const nextIds = currentTags()
            .map((tag) => tag.id)
            .filter((id) => id !== removeId);
          try {
            const saved = await saveTagsForTarget({ scope, path, tagIds: nextIds });
            setTags(saved);
            if (typeof loadIndex === 'function') loadIndex();
          } catch (error) {
            window.alert(error.message || 'Unable to update tags.');
          }
        });
      });
      const select = root.querySelector('.sp-tag-select');
      select?.addEventListener('change', async () => {
        const addId = Number(select.value || 0);
        select.value = '';
        if (!addId) return;
        const nextIds = [...new Set([...currentTags().map((tag) => tag.id), addId])];
        try {
          const saved = await saveTagsForTarget({ scope, path, tagIds: nextIds });
          setTags(saved);
          if (typeof loadIndex === 'function') loadIndex();
        } catch (error) {
          window.alert(error.message || 'Unable to update tags.');
        }
      });
      const createBtn = root.querySelector('.sp-tag-create-btn');
      const createInput = root.querySelector('.sp-tag-create-input');
      createBtn?.addEventListener('click', async () => {
        const label = String(createInput?.value || '').trim();
        if (!label) return;
        try {
          const created = await postCatalogAction('create_search_tag', { label });
          dialogAllTags = normalizeTagList(created.tags || [...dialogAllTags, created.tag]);
          if (catalogSearchState) catalogSearchState.allTags = dialogAllTags;
          const addId = Number(created.tag?.id || 0);
          if (addId) {
            const nextIds = [...new Set([...currentTags().map((tag) => tag.id), addId])];
            const saved = await saveTagsForTarget({ scope, path, tagIds: nextIds });
            setTags(saved);
          } else {
            renderProjectTagsPanel();
          }
          if (createInput) createInput.value = '';
          if (typeof loadIndex === 'function') loadIndex();
        } catch (error) {
          window.alert(error.message || 'Unable to create tag.');
        }
      });
    };

    const applyLoadedProject = (project, name, { seedExpanded = false } = {}) => {
      allItems = Array.isArray(project.items) ? project.items : [];
      prepareSearchItems(allItems);
      invalidatePreparedTree();
      if (seedExpanded) {
        expanded.clear();
        getPreparedTree(allItems, true).forEach((node) => {
          if (isFolderItem(node.item)) expanded.add(node.path.toLowerCase());
        });
      }
      const visibleForStats = catalogShowArchived() ? allItems : allItems.filter((item) => !item.archived);
      const folders = visibleForStats.filter((item) => isFolderItem(item)).length;
      const files = visibleForStats.length - folders;
      const catalogLabel = String(project.source_title || '').trim();
      currentCatalogTitle = catalogLabel || '';
      currentProjectTags = normalizeTagList(project.tags);
      const catalogBadge = catalogBadgeHtml(currentCatalogTitle, project.source_key || currentSourceKey);
      const archivedCount = allItems.filter((item) => item.archived).length;
      subEl.innerHTML = `${
        catalogBadge ? `${catalogBadge} · ` : ''
      }${project.archived ? `${archivedBadgeHtml()} · ` : ''}📦 <strong>${visibleForStats.length}</strong> item${visibleForStats.length === 1 ? '' : 's'} · 📁 <strong>${folders}</strong> folder${folders === 1 ? '' : 's'} · 📄 <strong>${files}</strong> file${files === 1 ? '' : 's'}${
        !catalogShowArchived() && archivedCount ? ` · ${archivedCount} archived` : ''
      }`;
      renderProjectActivityStats(
        statsEl,
        computeProjectActivityStats(visibleForStats, project.project_name || name)
      );
      renderProjectTagsPanel();

      const archiveProjectBtn = archiveToggleHtml({
        archived: !!project.archived,
        disabled: project.archive_scope === 'source',
        title:
          project.archive_scope === 'source'
            ? 'This catalog folder is archived. Unarchive the folder card first.'
            : project.archived
              ? 'Show this project on the dashboard again'
              : 'Hide this project from the dashboard',
        extraClass: 'sp-archive-project-btn',
        attrs: {
          'data-archive-scope': 'project',
          'data-source-key': project.source_key || currentSourceKey,
          'data-project-name': project.project_name || name,
        },
      });
      const favoriteProjectBtn = favoriteToggleHtml({
        favorited: !!project.favorited,
        title: project.favorited ? 'Remove from favorites' : 'Add to favorites',
        extraClass: 'sp-favorite-project-btn',
        attrs: {
          'data-scope': 'project',
          'data-source-key': project.source_key || currentSourceKey,
          'data-project-name': project.project_name || name,
        },
      });
      if (project.folder_url) {
        actionsEl.innerHTML = `<div class="sp-dialog-folder-actions">
            ${favoriteProjectBtn}
            <a class="button button-primary btn-accent-violet-solid sp-open-folder-btn" href="${escapeHtml(project.folder_url)}" target="_blank" rel="noopener noreferrer" title="Open project folder in SharePoint">🔗 Open</a>
            <button type="button" class="button ghost-light sp-copy-link-btn sp-project-copy-btn" data-copy-url="${escapeHtml(project.folder_url)}" data-label="📋" title="Copy folder link" aria-label="Copy folder link">📋</button>
            ${qrButtonHtml(project.folder_url, project.project_name || name, projectQrMeta())}
            ${archiveProjectBtn}
          </div>`;
        bindCopyLinkButtons(actionsEl);
        bindQrButtons(actionsEl);
      } else {
        const alone = [favoriteProjectBtn, archiveProjectBtn].filter(Boolean).join('');
        actionsEl.innerHTML = alone
          ? `<div class="sp-dialog-folder-actions">${alone}</div>`
          : '';
      }
      actionsEl.querySelectorAll('.sp-favorite-project-btn').forEach((btn) => {
        btn.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopPropagation();
          if (btn.disabled) return;
          const nextFavorited = btn.getAttribute('data-favorited') !== '1';
          try {
            await postFavorite({
              scope: 'project',
              sourceKey: currentSourceKey,
              projectName: currentName,
              favorited: nextFavorited,
            });
            if (project) project.favorited = nextFavorited;
            applyFavoriteButtonState(btn, nextFavorited);
            if (typeof loadIndex === 'function') loadIndex();
          } catch (error) {
            window.alert(error.message || 'Unable to update favorite.');
          }
        });
      });
      actionsEl.querySelectorAll('.sp-archive-project-btn').forEach((btn) => {
        btn.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopPropagation();
          if (btn.disabled) return;
          const nextArchived = btn.getAttribute('data-archived') !== '1';
          try {
            await postArchive({
              scope: 'project',
              sourceKey: currentSourceKey,
              projectName: currentName,
              archived: nextArchived,
            });
            await loadCurrentProject({ fresh: true });
            if (typeof loadIndex === 'function') loadIndex();
          } catch (error) {
            window.alert(error.message || 'Unable to update archive.');
          }
        });
      });

      if (searchWrap) searchWrap.hidden = false;
      applyFilter();
      bindItemTagEditors();
    };

    bindItemTagEditors = () => {
      if (!dialogCanEditTags || !rowsEl || hiddenCols.has('actions')) return;
      rowsEl.querySelectorAll('.sp-tag-chips[data-tag-scope="item"]').forEach((chipRoot) => {
        const path = chipRoot.getAttribute('data-tag-path') || '';
        const item = allItems.find((entry) => {
          const rel = String(entry.relative_path || entry.name || '').trim();
          return rel === path;
        });
        if (!item) return;
        chipRoot.querySelectorAll('.sp-tag-remove').forEach((btn) => {
          btn.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            const removeId = Number(btn.getAttribute('data-tag-id') || 0);
            const nextIds = normalizeTagList(item.tags)
              .map((tag) => tag.id)
              .filter((id) => id !== removeId);
            try {
              item.tags = await saveTagsForTarget({ scope: 'item', path, tagIds: nextIds });
              prepareSearchItem(item, { force: true });
              applyFilter();
              if (typeof loadIndex === 'function') loadIndex();
            } catch (error) {
              window.alert(error.message || 'Unable to update tags.');
            }
          });
        });
      });
      rowsEl.querySelectorAll('[data-col="actions"] .sp-item-tag-add select').forEach((select) => {
        select.addEventListener('change', async (event) => {
          const path =
            select.closest('tr')?.querySelector('.sp-tag-chips')?.getAttribute('data-tag-path') ||
            select.closest('tr')?.querySelector('.sharepoint-link-path')?.textContent ||
            select.closest('tr')?.querySelector('.sp-file-name')?.textContent ||
            '';
          const item = allItems.find((entry) => String(entry.relative_path || entry.name || '').trim() === String(path).trim());
          const addId = Number(event.target.value || 0);
          event.target.value = '';
          if (!item || !addId) return;
          const nextIds = [...new Set([...normalizeTagList(item.tags).map((tag) => tag.id), addId])];
          try {
            item.tags = await saveTagsForTarget({
              scope: 'item',
              path: String(item.relative_path || item.name || path).trim(),
              tagIds: nextIds,
            });
            prepareSearchItem(item, { force: true });
            applyFilter();
            if (typeof loadIndex === 'function') loadIndex();
          } catch (error) {
            window.alert(error.message || 'Unable to update tags.');
          }
        });
      });
    };

    bindItemArchiveButtons = () => {
      if (!catalogCanArchive() || !rowsEl) return;
      rowsEl.querySelectorAll('.sp-archive-item-btn').forEach((btn) => {
        btn.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopPropagation();
          if (btn.disabled) return;
          const scope = btn.getAttribute('data-archive-scope') || 'item';
          const path = btn.getAttribute('data-archive-path') || '';
          const sourceKey = btn.getAttribute('data-source-key') || currentSourceKey;
          const projectName = btn.getAttribute('data-project-name') || currentName;
          const nextArchived = btn.getAttribute('data-archived') !== '1';
          try {
            await postArchive({
              scope,
              sourceKey,
              projectName,
              relativePath: scope === 'item' ? path : '',
              archived: nextArchived,
            });
            await loadCurrentProject({ fresh: true });
            if (typeof loadIndex === 'function') loadIndex();
          } catch (error) {
            window.alert(error.message || 'Unable to update archive.');
          }
        });
      });
    };

    const loadCurrentProject = async ({ seedExpanded = false, fresh = false } = {}) => {
      const name = currentName;
      if (!name) return;
      const payload = await fetchProjectDetail(name, currentSourceKey, { fresh });
      const project = payload.project;
      currentSourceKey = String(project.source_key || currentSourceKey || '');
      dialogCanEditTags = !!payload.can_edit_tags && catalogCanEditTags();
      dialogAllTags = normalizeTagList(payload.all_tags || catalogSearchState?.allTags);
      if (dialogAllTags.length && catalogSearchState) catalogSearchState.allTags = dialogAllTags;
      applyLoadedProject(project, name, { seedExpanded });
    };

    const openProject = async (projectName, sourceKey = '', initialQuery = '') => {
      const name = String(projectName || '').trim();
      if (!name) return;

      currentName = name;
      currentSourceKey = String(sourceKey || '').trim();
      titleEl.textContent = name;
      subEl.innerHTML = '⏳ Loading SharePoint details…';
      clearActivityStats();
      if (tagsEl) {
        tagsEl.hidden = true;
        tagsEl.innerHTML = '';
      }
      actionsEl.innerHTML = '';
      currentProjectTags = [];
      allItems = [];
      invalidatePreparedTree();
      expanded.clear();
      lastRevealKey = '';
      selectedExts.clear();
      layout = 'tree';
      sortKey = 'name';
      sortDir = 'asc';
      syncHeaderSort();
      committedQuery = String(initialQuery || '').trim() || currentCatalogDialogQuery();
      if (searchInput) searchInput.value = committedQuery;
      syncPendingSearchHint();
      paintDialogSavedPresets();
      if (kindSelect) kindSelect.value = 'all';
      if (extSelect) extSelect.value = '';
      if (searchWrap) searchWrap.hidden = true;
      rowsEl.innerHTML = emptyRowHtml('⏳ Loading…');
      dialog.__spPrepareWorkspace?.();
      dialog.showModal();
      dialog.__spPrepareWorkspace?.();
      playWorkspaceDialogEnter(dialog);

      projectBusy = true;
      setDialogRefreshBusy(refreshBtn, true);
      try {
        await loadCurrentProject({ seedExpanded: true, fresh: false });
        window.setTimeout(() => searchInput?.focus(), 50);
      } catch (error) {
        subEl.textContent = '';
        clearActivityStats();
        if (searchWrap) searchWrap.hidden = true;
        rowsEl.innerHTML = emptyRowHtml(escapeHtml(error.message || 'Failed to load project.'));
      } finally {
        projectBusy = false;
        setDialogRefreshBusy(refreshBtn, false);
      }
    };

    refreshBtn?.addEventListener('click', async () => {
      if (!currentName || projectBusy) return;
      projectBusy = true;
      setDialogRefreshBusy(refreshBtn, true);
      try {
        await loadCurrentProject({ seedExpanded: false, fresh: true });
      } catch (error) {
        if (allItems.length === 0) {
          subEl.textContent = '';
          clearActivityStats();
          if (searchWrap) searchWrap.hidden = true;
          rowsEl.innerHTML = emptyRowHtml(escapeHtml(error.message || 'Failed to load project.'));
        } else if (subEl) {
          subEl.textContent = error.message || 'Refresh failed.';
        }
      } finally {
        projectBusy = false;
        setDialogRefreshBusy(refreshBtn, false);
      }
    });

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
    const refreshBtn = document.getElementById('sharepoint-compare-dialog-refresh');
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
        searchWrap: document.querySelector('.sharepoint-compare-search-field-wrap[data-side="left"]'),
        searchInput: document.querySelector('.sp-compare-panel-search[data-side="left"]'),
        searchBtn: document.querySelector('.sp-compare-panel-search-btn[data-side="left"]'),
        searchPending: document.querySelector('.sp-compare-panel-pending[data-side="left"]'),
        clearBtn: document.querySelector('.sp-compare-panel-clear[data-side="left"]'),
        meta: document.querySelector('.sp-compare-panel-meta[data-side="left"]'),
        expanded: new Set(),
        lastRevealKey: '',
        preparedTree: null,
      },
      mid: {
        panel: document.querySelector('.sharepoint-compare-panel[data-side="mid"]'),
        title: document.getElementById('sharepoint-compare-mid-title'),
        sub: document.getElementById('sharepoint-compare-mid-sub'),
        actions: document.getElementById('sharepoint-compare-mid-actions'),
        rows: document.getElementById('sharepoint-compare-mid-rows'),
        filters: document.querySelector('.sharepoint-compare-panel-filters[data-side="mid"]'),
        searchWrap: document.querySelector('.sharepoint-compare-search-field-wrap[data-side="mid"]'),
        searchInput: document.querySelector('.sp-compare-panel-search[data-side="mid"]'),
        searchBtn: document.querySelector('.sp-compare-panel-search-btn[data-side="mid"]'),
        searchPending: document.querySelector('.sp-compare-panel-pending[data-side="mid"]'),
        clearBtn: document.querySelector('.sp-compare-panel-clear[data-side="mid"]'),
        meta: document.querySelector('.sp-compare-panel-meta[data-side="mid"]'),
        expanded: new Set(),
        lastRevealKey: '',
        preparedTree: null,
      },
      right: {
        panel: document.querySelector('.sharepoint-compare-panel[data-side="right"]'),
        title: document.getElementById('sharepoint-compare-right-title'),
        sub: document.getElementById('sharepoint-compare-right-sub'),
        actions: document.getElementById('sharepoint-compare-right-actions'),
        rows: document.getElementById('sharepoint-compare-right-rows'),
        filters: document.querySelector('.sharepoint-compare-panel-filters[data-side="right"]'),
        searchWrap: document.querySelector('.sharepoint-compare-search-field-wrap[data-side="right"]'),
        searchInput: document.querySelector('.sp-compare-panel-search[data-side="right"]'),
        searchBtn: document.querySelector('.sp-compare-panel-search-btn[data-side="right"]'),
        searchPending: document.querySelector('.sp-compare-panel-pending[data-side="right"]'),
        clearBtn: document.querySelector('.sp-compare-panel-clear[data-side="right"]'),
        meta: document.querySelector('.sp-compare-panel-meta[data-side="right"]'),
        expanded: new Set(),
        lastRevealKey: '',
        preparedTree: null,
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
    let sortKey = 'name';
    let sortDir = 'asc';
    let syncSearchModes = () => readSearchPrefs();
    let compareBusy = false;
    /** @type {{ projectName: string, sourceKey: string }[]} */
    let lastComparePicks = [];
    /** @type {Set<string>} */
    let hiddenItemKeys = new Set();
    /** @type {Map<string, string>} */
    let hiddenItemLabels = new Map();
    /** @type {Set<string>} */
    let hiddenCols = readCompareHiddenCols();
    const COMPARE_PANE_WIDTHS_KEY = 'riskregister_sp_compare_pane_widths';
    const MIN_COMPARE_PANE_PX = 200;
    /** @type {number[]} */
    let comparePaneFractions = [];
    let comparePaneSplit = null;
    let comparePanePersistTimer = 0;

    const columnsPicker = document.getElementById('sharepoint-compare-columns-picker');
    const hiddenBar = document.getElementById('sharepoint-compare-hidden-bar');
    const hiddenLabel = document.getElementById('sharepoint-compare-hidden-label');
    const hiddenChips = document.getElementById('sharepoint-compare-hidden-chips');
    const showAllHiddenBtn = document.getElementById('sharepoint-compare-show-all-hidden');

    const visibleColspan = () => {
      let count = 2; // name + hide action always shown
      COMPARE_TOGGLE_COLS.forEach((col) => {
        if (!hiddenCols.has(col)) count += 1;
      });
      return Math.max(count, 2);
    };

    const emptyRowHtml = (message) =>
      `<tr><td colspan="${visibleColspan()}" class="sharepoint-dialog-empty">${message}</td></tr>`;

    const colResize = bindColumnResize(dialog, SEARCH_PREF.compareColWidths);

    const applyHiddenColsToDialog = () => {
      dialog.setAttribute('data-hidden-cols', [...hiddenCols].join(' '));
      columnsPicker?.querySelectorAll('input[data-col-toggle]').forEach((input) => {
        const col = input.getAttribute('data-col-toggle') || '';
        input.checked = !hiddenCols.has(col);
      });
      colResize.apply();
    };

    const setColumnHidden = (col, hide) => {
      if (!COMPARE_TOGGLE_COLS.includes(col)) return;
      if (hide) hiddenCols.add(col);
      else hiddenCols.delete(col);
      writeCompareHiddenCols(hiddenCols);
      applyHiddenColsToDialog();
      if (activeSides.every((side) => projectsBySide[side])) {
        applyCompareFilter();
      }
    };

    const hideItemByKey = (key, label) => {
      if (!key) return;
      hiddenItemKeys.add(key);
      hiddenItemLabels.set(key, label || key);
      refreshHiddenBar();
      applyCompareFilter();
    };

    const unhideItemByKey = (key) => {
      hiddenItemKeys.delete(key);
      hiddenItemLabels.delete(key);
      refreshHiddenBar();
      applyCompareFilter();
    };

    const clearHiddenItems = () => {
      hiddenItemKeys.clear();
      hiddenItemLabels.clear();
      refreshHiddenBar();
      applyCompareFilter();
    };

    const refreshHiddenBar = () => {
      if (!hiddenBar) return;
      const count = hiddenItemKeys.size;
      hiddenBar.hidden = count === 0;
      if (hiddenLabel) {
        hiddenLabel.textContent = `${count} hidden`;
      }
      if (hiddenChips) {
        hiddenChips.innerHTML = [...hiddenItemKeys]
          .map((key) => {
            const label = hiddenItemLabels.get(key) || key;
            return `<button type="button" class="sp-compare-hidden-chip" data-unhide-key="${escapeHtml(key)}" role="listitem" title="Show again">${escapeHtml(label)} ✕</button>`;
          })
          .join('');
      }
    };

    const filterOutHiddenItems = (items) =>
      (items || []).filter((item) => {
        if (hiddenItemKeys.has(itemKey(item))) return false;
        if (!catalogShowArchived() && item?.archived) return false;
        return true;
      });

    const compareRowExtrasHtml = (item, side, diff, tone, qrMeta = {}) => {
      const key = itemKey(item);
      const name = String(item?.name || '').trim() || key;
      const project = projectsBySide[side] || {};
      const archiveCtx = {
        sourceKey: String(project.source_key || qrMeta.sourceKey || '').trim(),
        projectName: String(project.project_name || '').trim(),
      };
      return `<td data-col="diff"><span class="sp-diff-pill sp-diff-pill--${tone}">${diffLabelFor(diff, side)}</span></td>
        <td data-col="hide" class="sp-compare-hide-cell">
          <button type="button" class="sp-compare-hide-btn" data-hide-key="${escapeHtml(key)}" data-hide-label="${escapeHtml(name)}" title="Hide from compare" aria-label="Hide ${escapeHtml(name)} from compare">👁‍🗨</button>
        </td>
        ${dialogActionsCellHtml(item, qrMeta)}
        ${dialogArchiveCellHtml(item, archiveCtx)}`;
    };

    const syncCompareHeaderSort = () => {
      dialog.querySelectorAll('.sharepoint-compare-panel thead th[data-sort]').forEach((th) => {
        const key = th.getAttribute('data-sort') || '';
        const active = key === sortKey;
        th.classList.toggle('is-sorted-asc', active && sortDir === 'asc');
        th.classList.toggle('is-sorted-desc', active && sortDir === 'desc');
        th.setAttribute('aria-sort', active ? (sortDir === 'desc' ? 'descending' : 'ascending') : 'none');
      });
    };

    const setCompareSort = (next) => {
      if (!SORT_KEYS.has(next)) return;
      if (sortKey === next) {
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
      } else {
        sortKey = next;
        sortDir = 'asc';
      }
      syncCompareHeaderSort();
      applyCompareFilter();
    };

    const keyFor = (item, side) =>
      compareItemKey(item, projectsBySide[side]?.project_name || item?.project_name || '');

    const assignProjectToSide = (side, project, { seedExpanded = false } = {}) => {
      projectsBySide[side] = project;
      itemsBySide[side] = Array.isArray(project.items) ? project.items : [];
      prepareSearchItems(itemsBySide[side]);
      sideEls[side].preparedTree = null;
      keysBySide[side] = new Set(itemsBySide[side].map((item) => compareItemKey(item, project?.project_name || '')));
      if (seedExpanded) {
        sideEls[side].expanded.clear();
        const tree = sideEls[side].preparedTree || (sideEls[side].preparedTree = buildTreeNodes(itemsBySide[side]));
        tree.forEach((node) => {
          if (isFolderItem(node.item)) sideEls[side].expanded.add(node.path.toLowerCase());
        });
      }
    };

    const currentComparePicks = () =>
      activeSides
        .map((side) => {
          const project = projectsBySide[side];
          const projectName = String(project?.project_name || '').trim();
          const sourceKey = String(project?.source_key || '').trim();
          return projectName ? { side, projectName, sourceKey } : null;
        })
        .filter(Boolean);

    const fetchAndApplyCompare = async (picks, { seedExpanded = false, fresh = false } = {}) => {
      const loaded = await Promise.all(
        picks.map((pick) => fetchProjectDetail(pick.projectName, pick.sourceKey, { fresh }))
      );
      picks.forEach((pick, index) => {
        const side = pick.side || activeSides[index];
        if (!side) return;
        const payload = loaded[index];
        const project = payload?.project && typeof payload.project === 'object' ? payload.project : payload;
        if (!project) return;
        assignProjectToSide(side, project, { seedExpanded });
      });
      refreshCompareSummary();
      legendEl.hidden = false;
      if (searchWrap) searchWrap.hidden = false;
      applyCompareFilter();
    };

    const emptySideFilter = () => ({ query: '', exts: new Set() });

    const syncComparePendingHint = (side) => {
      const els = sideEls[side];
      if (!els) return;
      const draft = els.searchInput?.value || '';
      const committed = filtersBySide[side]?.query || '';
      const pending = draft !== committed;
      els.searchWrap?.classList.toggle('has-pending-search', pending);
      els.searchBtn?.classList.toggle('is-pending', pending);
      if (els.searchPending) els.searchPending.hidden = !pending;
    };

    const resetSideFilter = (side) => {
      filtersBySide[side] = emptySideFilter();
      if (sideEls[side].searchInput) sideEls[side].searchInput.value = '';
      if (sideEls[side].clearBtn) sideEls[side].clearBtn.hidden = true;
      if (sideEls[side].meta) sideEls[side].meta.textContent = '';
      syncComparePendingHint(side);
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
      const project = projectsBySide[side] || {};
      const qrMeta = {
        sourceKey: String(project.source_key || '').trim(),
        catalog: String(project.source_title || project.source_key || '').trim(),
      };
      nodes.forEach((node) => {
        const key = keyFor(node.item, side);
        const diff = classifyDiff(key, side);
        const isFolder = isFolderItem(node.item);
        const hasKids = isFolder && node.children.length > 0;
        const isOpen = hasKids && expanded.has(node.path.toLowerCase());
        const toggle = hasKids
          ? `<button type="button" class="sp-tree-toggle" data-side="${side}" data-tree-path="${escapeHtml(node.path)}" aria-expanded="${isOpen ? 'true' : 'false'}">${isOpen ? '▼' : '▶'}</button>`
          : `<span class="sp-tree-toggle sp-tree-toggle--spacer" aria-hidden="true"></span>`;
        const tone = pillClassFor(diff);
        acc.push({
          html: `<tr class="sp-dialog-row sp-compare-row sp-compare-row--${tone}${isFolder ? ' sp-dialog-row--folder' : ''}" data-tree-path="${escapeHtml(node.path)}" data-tree-depth="${depth}">
            ${dialogItemCellsHtml(node.item, depth, toggle)}
            ${compareRowExtrasHtml(node.item, side, diff, tone, qrMeta)}
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
      const parsed = parseDialogQuery(query);
      const matchOpts = { ...prefs, parsed };
      let shared = 0;
      let only = 0;
      let shown = 0;

      const baseItems = filterOutHiddenItems(
        uniqueOnly ? items.filter((item) => !others.has(keyFor(item, side))) : items
      );

      if (layout === 'tree') {
        const canUseCache = !uniqueOnly && hiddenItemKeys.size === 0;
        let baseTree;
        if (canUseCache) {
          if (!sideEls[side].preparedTree) sideEls[side].preparedTree = buildTreeNodes(items);
          baseTree = sideEls[side].preparedTree;
        } else {
          baseTree = buildTreeNodes(baseItems);
        }
        const tree = sortTreeNodesBy(filterTree(baseTree, kind, ext, query, matchOpts), sortKey, sortDir);
        const key = `${treeRevealKey(query, kind, ext, prefs)}|${uniqueOnly ? '1' : '0'}|${sortKey}:${sortDir}`;
        if (sideEls[side].lastRevealKey !== key) {
          sideEls[side].lastRevealKey = key;
          if (String(query || '').trim() !== '' || ext.length > 0 || uniqueOnly) {
            const fullCount = countTreeRowsIfExpanded(tree);
            if (fullCount <= DIALOG_TREE_EXPAND_ROW_CAP) {
              collectTreeFolderPaths(tree).forEach((path) => sideEls[side].expanded.add(path));
            }
          }
        }
        const acc = [];
        renderCompareTree(tree, side, 0, acc, filterState);
        shown = acc.length;
        acc.forEach((row) => {
          shared += row.shared;
          only += row.only;
        });
        const skipSettle = String(query || '').trim() !== '' || acc.length > DIALOG_SETTLE_ROW_CAP;
        const previousTops = skipSettle ? null : snapshotTreeRowTops(rowsEl);
        rowsEl.innerHTML =
          acc.length > 0
            ? acc.map((row) => row.html).join('')
            : emptyRowHtml(
                items.length === 0
                  ? 'No files or folders.'
                  : uniqueOnly
                    ? 'No unique files or folders on this side.'
                    : 'No matches for this panel filter. Try OR mode or Fuzzy.'
              );
        if (previousTops && acc.length > 0) settleTreeRows(rowsEl, previousTops);
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
        const filtered = sortItemsBy(flattenFiltered(baseItems, kind, ext, query, matchOpts), sortKey, sortDir);
        shown = filtered.length;
        if (filtered.length === 0) {
          rowsEl.innerHTML = emptyRowHtml(
            items.length === 0
              ? 'No files or folders.'
              : uniqueOnly
                ? 'No unique files or folders on this side.'
                : 'No matches for this panel filter. Try OR mode or Fuzzy.'
          );
        } else {
          rowsEl.innerHTML = filtered
            .map((item) => {
              const key = keyFor(item, side);
              const diff = classifyDiff(key, side);
              const tone = pillClassFor(diff);
              if (diff === 'all' || diff === 'shared') shared += 1;
              else only += 1;
              const project = projectsBySide[side] || {};
              const qrMeta = {
                sourceKey: String(project.source_key || '').trim(),
                catalog: String(project.source_title || project.source_key || '').trim(),
              };
              return `<tr class="sp-dialog-row sp-compare-row sp-compare-row--${tone}${isFolderItem(item) ? ' sp-dialog-row--folder' : ''}">
                ${dialogItemCellsHtml(item, 0, '')}
                ${compareRowExtrasHtml(item, side, diff, tone, qrMeta)}
              </tr>`;
            })
            .join('');
        }
      }

      bindCopyLinkButtons(rowsEl);
      bindQrButtons(rowsEl);
      bindCompareItemArchiveButtons(rowsEl);
      return { shared, only, shown, query, ext };
    };

    const bindCompareItemArchiveButtons = (root) => {
      if (!catalogCanArchive() || !root) return;
      root.querySelectorAll('.sp-archive-item-btn').forEach((btn) => {
        btn.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopPropagation();
          if (btn.disabled) return;
          const scope = btn.getAttribute('data-archive-scope') || 'item';
          const path = btn.getAttribute('data-archive-path') || '';
          const sourceKey = btn.getAttribute('data-source-key') || '';
          const projectName = btn.getAttribute('data-project-name') || '';
          const nextArchived = btn.getAttribute('data-archived') !== '1';
          if (!sourceKey || !projectName) return;
          try {
            await postArchive({
              scope,
              sourceKey,
              projectName,
              relativePath: scope === 'item' ? path : '',
              archived: nextArchived,
            });
            const picks = currentComparePicks();
            if (picks.length) {
              await fetchAndApplyCompare(picks, { fresh: true });
            }
            if (typeof loadIndex === 'function') loadIndex();
          } catch (error) {
            window.alert(error.message || 'Unable to update archive.');
          }
        });
      });
    };

    const fillSide = (side, project, stats) => {
      const els = sideEls[side];
      const catalog = String(project.source_title || project.source_key || '').trim();
      const catalogBadge = catalogBadgeHtml(catalog, project.source_key);
      const items = Array.isArray(project.items) ? project.items : [];
      els.title.textContent = String(project.project_name || 'Project');
      els.sub.innerHTML = `${
        catalogBadge ? `${catalogBadge} · ` : ''
      }${items.length} item${items.length === 1 ? '' : 's'} · showing ${stats.shown}`;
      if (project.folder_url) {
        els.actions.innerHTML = `<div class="sp-dialog-folder-actions sp-compare-folder-actions">
          <a class="button button-primary btn-accent-violet-solid sp-compare-open-btn" href="${escapeHtml(project.folder_url)}" target="_blank" rel="noopener noreferrer">🔗 Open</a>
          <button type="button" class="button ghost-light sp-copy-link-btn sp-compare-copy-btn" data-copy-url="${escapeHtml(project.folder_url)}" data-label="📋" title="Copy folder link" aria-label="Copy folder link">📋</button>
          ${qrButtonHtml(project.folder_url, project.project_name || '', {
            sourceKey: project.source_key || '',
            catalog,
          })}
        </div>`;
        bindCopyLinkButtons(els.actions);
        bindQrButtons(els.actions);
      } else {
        els.actions.innerHTML = '';
      }

      syncExtChips(els.filters, items, filtersBySide[side].exts);
      if (els.clearBtn) {
        const draft = String(els.searchInput?.value || '').trim();
        els.clearBtn.hidden =
          String(filtersBySide[side].query || '').trim() === '' &&
          filtersBySide[side].exts.size === 0 &&
          draft === '';
      }
      syncComparePendingHint(side);
      if (els.meta) {
        const bits = [`${stats.shown} shown`, `of ${items.length}`];
        if (stats.ext?.length) bits.push(stats.ext.map((value) => `.${value}`).join(' + '));
        bits.push(...formatSearchModeBits(stats.query || '', readSearchPrefs()));
        els.meta.textContent = bits.join(' · ');
      }
    };

    const comparePanesStacked = () => window.matchMedia('(max-width: 1100px)').matches;

    const visibleComparePanels = () =>
      activeSides.map((side) => sideEls[side]?.panel).filter((panel) => panel && !panel.hidden);

    const defaultComparePaneFractions = (count) => Array.from({ length: Math.max(2, count) }, () => 1);

    const readComparePaneFractions = (count) => {
      try {
        const raw = JSON.parse(localStorage.getItem(COMPARE_PANE_WIDTHS_KEY) || 'null');
        const list = raw && typeof raw === 'object' ? raw[String(count)] : null;
        if (
          Array.isArray(list) &&
          list.length === count &&
          list.every((value) => Number(value) > 0)
        ) {
          return list.map((value) => Number(value));
        }
      } catch {
        /* ignore */
      }
      return defaultComparePaneFractions(count);
    };

    const persistComparePaneFractions = () => {
      if (comparePaneFractions.length < 2) return;
      try {
        const raw = JSON.parse(localStorage.getItem(COMPARE_PANE_WIDTHS_KEY) || '{}') || {};
        const next = raw && typeof raw === 'object' ? raw : {};
        next[String(comparePaneFractions.length)] = comparePaneFractions.map((value) =>
          Number(Number(value).toFixed(4))
        );
        localStorage.setItem(COMPARE_PANE_WIDTHS_KEY, JSON.stringify(next));
      } catch {
        /* ignore */
      }
    };

    const scheduleComparePanePersist = () => {
      window.clearTimeout(comparePanePersistTimer);
      comparePanePersistTimer = window.setTimeout(persistComparePaneFractions, 160);
    };

    const applyComparePaneFractions = () => {
      if (!panelsEl) return;
      if (comparePanesStacked() || activeSides.length < 2 || comparePaneFractions.length < 2) {
        panelsEl.style.removeProperty('--sp-compare-cols');
        return;
      }
      const cols = comparePaneFractions
        .map((value) => `minmax(0, ${Number(value) > 0 ? Number(value) : 1}fr)`)
        .join(' ');
      panelsEl.style.setProperty('--sp-compare-cols', cols);
    };

    const layoutCompareSplitters = () => {
      if (!panelsEl) return;
      const splitters = Array.from(panelsEl.querySelectorAll('.sharepoint-compare-splitter'));
      const stacked = comparePanesStacked();
      const visible = visibleComparePanels();
      const hostRect = panelsEl.getBoundingClientRect();
      splitters.forEach((splitter, index) => {
        const show = !stacked && visible.length >= 2 && index < visible.length - 1;
        splitter.hidden = !show;
        splitter.tabIndex = show ? 0 : -1;
        if (!show) return;
        const leftRect = visible[index].getBoundingClientRect();
        const rightRect = visible[index + 1].getBoundingClientRect();
        const x = (leftRect.right + rightRect.left) / 2 - hostRect.left + panelsEl.scrollLeft;
        splitter.style.left = `${Math.round(x)}px`;
        const total = comparePaneFractions.reduce((sum, value) => sum + value, 0) || 1;
        const leftShare = Math.round(((comparePaneFractions[index] || 1) / total) * 100);
        splitter.setAttribute('aria-valuenow', String(leftShare));
        splitter.setAttribute(
          'aria-label',
          visible.length === 2
            ? 'Resize compare panels'
            : `Resize panel ${index + 1} and panel ${index + 2}`
        );
      });
    };

    const ensureComparePaneFractions = ({ reload = false } = {}) => {
      const count = Math.max(2, activeSides.length);
      if (reload || comparePaneFractions.length !== count) {
        comparePaneFractions = readComparePaneFractions(count);
      }
      applyComparePaneFractions();
      window.requestAnimationFrame(layoutCompareSplitters);
    };

    const comparePaneContentWidth = () => {
      if (!panelsEl) return 1;
      const styles = window.getComputedStyle(panelsEl);
      const gap = Number.parseFloat(styles.columnGap || styles.gap || '0') || 0;
      const count = Math.max(2, activeSides.length);
      return Math.max(1, panelsEl.clientWidth - gap * Math.max(0, count - 1));
    };

    const comparePaneWidthsFromFractions = (fractions = comparePaneFractions) => {
      const total = fractions.reduce((sum, value) => sum + Number(value), 0) || 1;
      const width = comparePaneContentWidth();
      return fractions.map((value) => (Number(value) / total) * width);
    };

    const setComparePaneWidths = (widths) => {
      const total = widths.reduce((sum, value) => sum + Math.max(0, Number(value)), 0) || 1;
      comparePaneFractions = widths.map((value) => Math.max(0.01, Number(value) / total));
      applyComparePaneFractions();
      layoutCompareSplitters();
      scheduleComparePanePersist();
    };

    const resizeComparePanePair = (index, deltaPx, startWidths = null) => {
      const widths = (startWidths || comparePaneWidthsFromFractions()).slice();
      if (index < 0 || index >= widths.length - 1) return;
      let left = widths[index] + deltaPx;
      let right = widths[index + 1] - deltaPx;
      if (left < MIN_COMPARE_PANE_PX) {
        right -= MIN_COMPARE_PANE_PX - left;
        left = MIN_COMPARE_PANE_PX;
      }
      if (right < MIN_COMPARE_PANE_PX) {
        left -= MIN_COMPARE_PANE_PX - right;
        right = MIN_COMPARE_PANE_PX;
      }
      if (left < MIN_COMPARE_PANE_PX || right < MIN_COMPARE_PANE_PX) return;
      widths[index] = left;
      widths[index + 1] = right;
      setComparePaneWidths(widths);
    };

    const resetComparePaneFractions = () => {
      comparePaneFractions = defaultComparePaneFractions(Math.max(2, activeSides.length));
      applyComparePaneFractions();
      layoutCompareSplitters();
      persistComparePaneFractions();
    };

    const endComparePaneSplit = (event) => {
      if (!comparePaneSplit) return;
      if (event && event.pointerId !== comparePaneSplit.pointerId) return;
      const splitter = comparePaneSplit.splitter;
      comparePaneSplit = null;
      panelsEl?.classList.remove('is-pane-splitting');
      splitter?.classList.remove('is-active');
      persistComparePaneFractions();
      try {
        splitter?.releasePointerCapture(event.pointerId);
      } catch {
        /* ignore */
      }
    };

    const bindComparePaneSplitters = () => {
      if (!panelsEl || panelsEl.dataset.splitBound === '1') return;
      panelsEl.dataset.splitBound = '1';
      panelsEl.querySelectorAll('.sharepoint-compare-splitter').forEach((splitter) => {
        splitter.addEventListener('pointerdown', (event) => {
          if (event.button !== 0 || comparePanesStacked() || activeSides.length < 2) return;
          event.preventDefault();
          event.stopPropagation();
          comparePaneSplit = {
            pointerId: event.pointerId,
            startX: event.clientX,
            index: Number(splitter.getAttribute('data-split-index') || '0') || 0,
            startWidths: comparePaneWidthsFromFractions(),
            splitter,
          };
          panelsEl.classList.add('is-pane-splitting');
          splitter.classList.add('is-active');
          try {
            splitter.setPointerCapture(event.pointerId);
          } catch {
            /* ignore */
          }
        });
        splitter.addEventListener('pointermove', (event) => {
          if (!comparePaneSplit || event.pointerId !== comparePaneSplit.pointerId) return;
          resizeComparePanePair(
            comparePaneSplit.index,
            event.clientX - comparePaneSplit.startX,
            comparePaneSplit.startWidths
          );
        });
        splitter.addEventListener('pointerup', endComparePaneSplit);
        splitter.addEventListener('pointercancel', endComparePaneSplit);
        splitter.addEventListener('dblclick', (event) => {
          event.preventDefault();
          resetComparePaneFractions();
        });
        splitter.addEventListener('keydown', (event) => {
          if (comparePanesStacked() || activeSides.length < 2) return;
          const index = Number(splitter.getAttribute('data-split-index') || '0') || 0;
          const step = event.shiftKey ? 72 : 28;
          if (event.key === 'ArrowLeft') {
            event.preventDefault();
            resizeComparePanePair(index, -step);
          } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            resizeComparePanePair(index, step);
          } else if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            resetComparePaneFractions();
          }
        });
      });

      if (typeof ResizeObserver === 'function') {
        const paneResizeObserver = new ResizeObserver(() => {
          if (!dialog.open) return;
          applyComparePaneFractions();
          layoutCompareSplitters();
        });
        paneResizeObserver.observe(panelsEl);
        paneResizeObserver.observe(dialog);
      }
      window.addEventListener('resize', () => {
        if (!dialog.open) return;
        applyComparePaneFractions();
        layoutCompareSplitters();
      });
    };

    const syncPanelVisibility = () => {
      SIDE_IDS.forEach((side) => {
        const active = activeSides.includes(side);
        if (sideEls[side].panel) sideEls[side].panel.hidden = !active;
      });
      if (panelsEl) panelsEl.setAttribute('data-panel-count', String(activeSides.length));
      ensureComparePaneFractions();
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
      sideEls[side].lastRevealKey = '';
      sideEls[side].preparedTree = null;
      resetSideFilter(side);
      sideEls[side].actions.innerHTML = '';
      sideEls[side].rows.innerHTML = emptyRowHtml('Select folders to compare.');
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
      prepareSearchItems(itemsBySide[side]);
      sideEls[side].preparedTree = null;
      keysBySide[side] = new Set(
        itemsBySide[side].map((item) => compareItemKey(item, snap.project?.project_name || ''))
      );
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
      syncComparePendingHint(side);
      sideEls[side].lastRevealKey = `${treeRevealKey(
        filtersBySide[side].query,
        kindSelect?.value || 'all',
        [...(filtersBySide[side].exts || [])],
        readSearchPrefs()
      )}|${uniqueOnlyEl?.checked ? '1' : '0'}`;
    };

    const refreshCompareSummary = () => {
      if (activeSides.some((side) => !projectsBySide[side])) return;
      const names = activeSides.map((side) => String(projectsBySide[side].project_name || ''));
      const uniqueNames = [...new Set(names.filter(Boolean))];
      titleEl.textContent =
        uniqueNames.length === 1 ? uniqueNames[0] : uniqueNames.join(' · ') || 'Compare';

      const allIn = itemsBySide[activeSides[0]].filter((item) => {
        const key = keyFor(item, activeSides[0]);
        return activeSides.every((side) => keysBySide[side].has(key));
      }).length;
      const onlyCounts = activeSides.map((side) => {
        const others = otherKeysUnion(side);
        return itemsBySide[side].filter((item) => !others.has(keyFor(item, side))).length;
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

    let swapAnimating = false;

    const prefersReducedMotion = () =>
      window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;

    const commitCompareSwap = (sideA, sideB) => {
      const snapA = snapshotSide(sideA);
      const snapB = snapshotSide(sideB);
      restoreSide(sideA, snapB);
      restoreSide(sideB, snapA);
      applyCompareFilter();
    };

    const clearSwapMotion = (panelA, panelB) => {
      [panelA, panelB].forEach((panel) => {
        panel.classList.remove('is-swapping', 'is-swapping-front');
        panel.style.transform = '';
      });
      panelsEl?.classList.remove('is-swapping');
    };

    const playSwapAnimation = (panelA, panelB, firstA, firstB) => {
      const dxA = firstB.left - firstA.left;
      const dyA = firstB.top - firstA.top;
      const dxB = firstA.left - firstB.left;
      const dyB = firstA.top - firstB.top;
      if (dxA === 0 && dyA === 0) return Promise.resolve();

      const aMovesForward = dxA < 0 || (dxA === 0 && dyA < 0);
      panelsEl?.classList.add('is-swapping');
      panelA.classList.add('is-swapping');
      panelB.classList.add('is-swapping');
      (aMovesForward ? panelA : panelB).classList.add('is-swapping-front');

      panelA.style.transform = `translate(${dxA}px, ${dyA}px)`;
      panelB.style.transform = `translate(${dxB}px, ${dyB}px)`;

      const duration = 520;
      const easing = 'cubic-bezier(0.22, 1, 0.36, 1)';
      const hopAt = (dx, dy, isFront) => {
        const vertical = Math.abs(dy) >= Math.abs(dx);
        const midX = dx * 0.5;
        const midY = dy * 0.5;
        const scale = isFront ? 1.03 : 0.985;
        if (vertical) {
          const nudge = isFront ? 14 : -8;
          return `translate(${midX + nudge}px, ${midY}px) scale(${scale})`;
        }
        const nudge = isFront ? -12 : 8;
        return `translate(${midX}px, ${midY + nudge}px) scale(${scale})`;
      };
      const keyframesFor = (dx, dy, isFront) => [
        { transform: `translate(${dx}px, ${dy}px) scale(1)` },
        { transform: hopAt(dx, dy, isFront), offset: 0.46 },
        { transform: 'translate(0px, 0px) scale(1)' },
      ];

      return new Promise((resolve) => {
        const play = () => {
          const frontA = panelA.classList.contains('is-swapping-front');
          const animA = panelA.animate(keyframesFor(dxA, dyA, frontA), {
            duration,
            easing,
            fill: 'forwards',
          });
          const animB = panelB.animate(keyframesFor(dxB, dyB, !frontA), {
            duration,
            easing,
            fill: 'forwards',
          });
          panelA.style.transform = '';
          panelB.style.transform = '';
          Promise.all([animA.finished.catch(() => {}), animB.finished.catch(() => {})]).then(() => {
            animA.cancel();
            animB.cancel();
            clearSwapMotion(panelA, panelB);
            resolve();
          });
        };
        window.requestAnimationFrame(play);
      });
    };

    const swapCompareSides = (sideA, sideB) => {
      if (swapAnimating) return;
      if (!activeSides.includes(sideA) || !activeSides.includes(sideB) || sideA === sideB) return;
      const panelA = sideEls[sideA].panel;
      const panelB = sideEls[sideB].panel;
      const firstA = panelA?.getBoundingClientRect?.();
      const firstB = panelB?.getBoundingClientRect?.();
      const canAnimate =
        panelA &&
        panelB &&
        firstA &&
        firstB &&
        firstA.width > 1 &&
        firstB.width > 1 &&
        typeof panelA.animate === 'function' &&
        !prefersReducedMotion();

      if (!canAnimate) {
        commitCompareSwap(sideA, sideB);
        refreshCompareSummary();
        return;
      }

      swapAnimating = true;
      commitCompareSwap(sideA, sideB);
      playSwapAnimation(panelA, panelB, firstA, firstB)
        .catch(() => {
          clearSwapMotion(panelA, panelB);
        })
        .finally(() => {
          refreshCompareSummary();
          swapAnimating = false;
          layoutCompareSplitters();
        });
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
      // Prefer draft text for AND/OR visibility while typing; fall back to committed queries.
      const sampleQuery =
        activeSides.map((side) => sideEls[side].searchInput?.value || '').find((q) => String(q).trim()) ||
        activeSides.map((side) => filtersBySide[side].query).find((q) => q.trim()) ||
        '';
      const prefs = syncSearchModes(sampleQuery);
      syncLayoutButtons();
      syncPanelVisibility();
      syncCompareHeaderSort();

      const statsBySide = {};
      activeSides.forEach((side) => {
        syncComparePendingHint(side);
        statsBySide[side] = renderSide(side, kind, uniqueOnly, prefs);
        fillSide(side, projectsBySide[side], statsBySide[side]);
      });
    };

    const commitCompareSideSearch = (side) => {
      filtersBySide[side].query = sideEls[side].searchInput?.value || '';
      syncComparePendingHint(side);
      applyCompareFilter();
      paintDialogSavedPresets();
    };

    const applyQueryToCompareSides = (query, sides = SIDE_IDS) => {
      const next = String(query || '');
      sides.forEach((side) => {
        if (!sideEls[side]) return;
        filtersBySide[side] = filtersBySide[side] || emptySideFilter();
        filtersBySide[side].query = next;
        if (sideEls[side].searchInput) sideEls[side].searchInput.value = next;
        syncComparePendingHint(side);
      });
    };

    bindDialogSavedPresets('sharepoint-compare-dialog-saved-chips', {
      getActiveQuery: () =>
        activeSides.map((side) => String(filtersBySide[side]?.query || '').trim()).find((query) => query) ||
        String(filtersBySide.left?.query || ''),
      onApply: (query) => {
        applyQueryToCompareSides(query, activeSides.length ? activeSides : SIDE_IDS);
        applyCompareFilter();
      },
    });

    SIDE_IDS.forEach((side) => {
      const fieldWrap = sideEls[side].searchWrap;
      if (!fieldWrap || fieldWrap.querySelector('[data-compare-saved-chips]')) return;
      const row = document.createElement('div');
      row.className = 'sharepoint-dialog-saved-searches sharepoint-dialog-saved-searches--panel';
      row.hidden = true;
      row.innerHTML = `<div class="sharepoint-recent-chips" data-compare-saved-chips="${side}" role="list" aria-label="Saved searches"></div>`;
      fieldWrap.insertBefore(row, fieldWrap.firstChild);
      bindDialogSavedPresets(row.querySelector('[data-compare-saved-chips]'), {
        getActiveQuery: () => String(filtersBySide[side]?.query || ''),
        onApply: (query) => {
          applyQueryToCompareSides(query, activeSides.length ? activeSides : SIDE_IDS);
          applyCompareFilter();
        },
      });
    });

    syncSearchModes = bindDialogSearchModes(searchWrap, applyCompareFilter);

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
        syncComparePendingHint(side);
        if (sideEls[side].clearBtn) {
          const draft = String(sideEls[side].searchInput?.value || '').trim();
          sideEls[side].clearBtn.hidden =
            String(filtersBySide[side].query || '').trim() === '' &&
            filtersBySide[side].exts.size === 0 &&
            draft === '';
        }
        // Keep AND/OR visibility in sync while typing without re-filtering.
        const sampleQuery =
          activeSides.map((s) => sideEls[s].searchInput?.value || '').find((q) => String(q).trim()) || '';
        syncSearchModes(sampleQuery);
      });
      sideEls[side].searchInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        if (event.isComposing || event.keyCode === 229) return;
        event.preventDefault();
        commitCompareSideSearch(side);
      });
      sideEls[side].searchBtn?.addEventListener('click', () => commitCompareSideSearch(side));
      sideEls[side].clearBtn?.addEventListener('click', () => {
        resetSideFilter(side);
        applyCompareFilter();
        paintDialogSavedPresets();
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
      const hideBtn = event.target.closest('.sp-compare-hide-btn');
      if (hideBtn) {
        event.preventDefault();
        event.stopPropagation();
        hideItemByKey(hideBtn.getAttribute('data-hide-key') || '', hideBtn.getAttribute('data-hide-label') || '');
        return;
      }
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

    dialog.querySelectorAll('.sharepoint-compare-panel thead .sp-dialog-sort-btn[data-sort]').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        setCompareSort(btn.getAttribute('data-sort') || 'name');
      });
    });

    columnsPicker?.querySelectorAll('input[data-col-toggle]').forEach((input) => {
      input.addEventListener('change', () => {
        const col = input.getAttribute('data-col-toggle') || '';
        setColumnHidden(col, !input.checked);
      });
    });

    // Keep the columns menu open when clicking checkboxes inside details.
    columnsPicker?.querySelector('.sp-compare-columns-menu')?.addEventListener('click', (event) => {
      event.stopPropagation();
    });
    bindColumnsMenuClose(columnsPicker);

    hiddenChips?.addEventListener('click', (event) => {
      const chip = event.target.closest('[data-unhide-key]');
      if (!chip) return;
      event.preventDefault();
      unhideItemByKey(chip.getAttribute('data-unhide-key') || '');
    });

    showAllHiddenBtn?.addEventListener('click', (event) => {
      event.preventDefault();
      clearHiddenItems();
    });

    applyHiddenColsToDialog();
    syncCompareHeaderSort();
    refreshHiddenBar();

    const slotSidesForCount = (count) => {
      if (count >= 3) return ['left', 'mid', 'right'];
      return ['left', 'right'];
    };

    const openCompare = async (picks) => {
      const list = Array.isArray(picks) ? picks.filter(Boolean) : [];
      if (list.length < 2 || list.length > 3) return;

      activeSides = slotSidesForCount(list.length);
      lastComparePicks = list.map((pick) => ({
        projectName: String(pick.projectName || '').trim(),
        sourceKey: String(pick.sourceKey || '').trim(),
      }));
      titleEl.textContent = 'Compare folders';
      subEl.textContent = `Loading ${list.length} catalogs…`;
      legendEl.hidden = true;
      if (searchWrap) searchWrap.hidden = true;
      if (kindSelect) kindSelect.value = 'all';
      if (uniqueOnlyEl) uniqueOnlyEl.checked = false;
      layout = 'tree';
      sortKey = 'name';
      sortDir = 'asc';
      hiddenItemKeys.clear();
      hiddenItemLabels.clear();
      refreshHiddenBar();
      syncCompareHeaderSort();
      applyHiddenColsToDialog();

      SIDE_IDS.forEach((side) => {
        sideEls[side].expanded.clear();
        sideEls[side].lastRevealKey = '';
        resetSideFilter(side);
        projectsBySide[side] = null;
        itemsBySide[side] = [];
        keysBySide[side] = new Set();
        sideEls[side].actions.innerHTML = '';
        sideEls[side].rows.innerHTML = emptyRowHtml('⏳ Loading…');
      });
      applyQueryToCompareSides(currentCatalogDialogQuery());
      paintDialogSavedPresets();

      activeSides.forEach((side, index) => {
        sideEls[side].title.textContent = list[index].projectName || `Folder ${index + 1}`;
        sideEls[side].sub.textContent = 'Loading…';
      });
      syncPanelVisibility();
      dialog.__spPrepareWorkspace?.();
      dialog.showModal();
      dialog.__spPrepareWorkspace?.();
      playWorkspaceDialogEnter(dialog);
      window.requestAnimationFrame(() => {
        ensureComparePaneFractions();
        layoutCompareSplitters();
      });

      compareBusy = true;
      setDialogRefreshBusy(refreshBtn, true);
      try {
        const slotted = activeSides.map((side, index) => ({
          side,
          projectName: list[index].projectName,
          sourceKey: list[index].sourceKey,
        }));
        await fetchAndApplyCompare(slotted, { seedExpanded: true, fresh: false });
        lastComparePicks = currentComparePicks().map((pick) => ({
          projectName: pick.projectName,
          sourceKey: pick.sourceKey,
        }));
        window.setTimeout(() => sideEls[activeSides[0]]?.searchInput?.focus(), 50);
      } catch (error) {
        subEl.textContent = error.message || 'Compare failed.';
        if (searchWrap) searchWrap.hidden = true;
        activeSides.forEach((side) => {
          sideEls[side].rows.innerHTML = emptyRowHtml(escapeHtml(error.message || 'Failed'));
        });
      } finally {
        compareBusy = false;
        setDialogRefreshBusy(refreshBtn, false);
      }
    };

    refreshBtn?.addEventListener('click', async () => {
      let picks = currentComparePicks();
      if (picks.length < 2 && lastComparePicks.length >= 2) {
        picks = activeSides
          .map((side, index) => {
            const pick = lastComparePicks[index];
            if (!pick?.projectName) return null;
            return { side, projectName: pick.projectName, sourceKey: pick.sourceKey || '' };
          })
          .filter(Boolean);
      }
      if (picks.length < 2 || compareBusy) return;
      compareBusy = true;
      setDialogRefreshBusy(refreshBtn, true);
      try {
        await fetchAndApplyCompare(picks, { seedExpanded: false, fresh: true });
        lastComparePicks = currentComparePicks().map((pick) => ({
          projectName: pick.projectName,
          sourceKey: pick.sourceKey,
        }));
      } catch (error) {
        subEl.textContent = error.message || 'Refresh failed.';
      } finally {
        compareBusy = false;
        setDialogRefreshBusy(refreshBtn, false);
      }
    });

    closeBtn?.addEventListener('click', () => dialog.close());
    bindComparePaneSplitters();
    dialog.addEventListener('close', () => {
      endComparePaneSplit();
      persistComparePaneFractions();
    });

    return openCompare;
  };

  const openProject = initDialog();
  const openCompare = initCompareDialog();
  window.RiskRegisterSharePoint = Object.assign(window.RiskRegisterSharePoint || {}, {
    openProject,
    openCompare,
    escapeHtml,
    catalogBadgeHtml,
    catalogToneFor,
    catalogApiUrl,
    fetchProjectDetail,
    bindCopyLinkButtons,
    bindQrButtons,
    qrButtonHtml,
    bindWorkspaceDialog,
    bindDialogSearchModes,
    bindDialogSavedPresets,
    readSearchPrefs,
    playWorkspaceDialogEnter,
    playWorkspaceDialogLeave,
    fileExtension,
    resolveMeta,
    formatModified,
    formatSize,
    FILE_META,
  });

  bindListDensityToggle();
  bindListColumnsPicker();
  bindCatalogDensityToggle();
  bindCatalogColorToggle();

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
    bindQrButtons(tbody);
    return;
  }

  const publicShare = searchRoot.getAttribute('data-public') === '1';
  const STORAGE = {
    wordMode: 'riskregister_sp_search_word_mode',
    fuzzy: 'riskregister_sp_search_fuzzy',
    deep: 'riskregister_sp_search_deep',
    suggest: 'riskregister_sp_search_suggest',
    scopes: publicShare ? 'riskregister_sp_public_search_scopes' : 'riskregister_sp_search_scopes',
    recent: publicShare ? 'riskregister_sp_public_search_recent' : 'riskregister_sp_search_recent',
    saved: publicShare ? 'riskregister_sp_public_search_saved' : 'riskregister_sp_search_saved',
    listFilters: 'riskregister_sp_list_filters_open',
    advancedOpen: publicShare ? 'riskregister_sp_public_search_advanced' : 'riskregister_sp_search_advanced',
    archived: 'riskregister_sp_search_archived',
    favorites: 'riskregister_sp_search_favorites',
  };
  const RECENT_MAX = 10;
  const RECENT_MIN_LEN = 2;
  const SAVED_MAX = 12;
  const IMAGE_EXTS = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp']);
  const CAD_EXTS = new Set(['dwg', 'dxf']);
  const VISIO_EXTS = new Set(['vsdx', 'vsd']);
  const TYPE_TRAIT_EXTS = {
    word: new Set(['doc', 'docx', 'docm', 'rtf']),
    excel: new Set(['xls', 'xlsx', 'xlsm', 'csv']),
    powerpoint: new Set(['ppt', 'pptx', 'pptm']),
    email: new Set(['msg', 'eml']),
    archive: new Set(['zip', '7z', 'rar']),
  };
  const TYPE_CHIP_KEYS = new Set(['pdf', 'word', 'excel', 'powerpoint', 'visio', 'email', 'archive', 'folders']);
  const HAS_LACK_KEYS = new Set(['pdf', 'visio', 'empty', 'stale']);
  const MATCH_SCOPES = new Set(['all', 'names', 'files', 'people']);
  const DATE_PRESETS = new Set(['', '7d', '30d', 'year', 'custom']);
  const PRESENCE_MODES = new Set(['any', 'all', 'only', 'missing']);
  const DAY_MS = 86400000;
  const currentUser = {
    name: (searchRoot.getAttribute('data-user-name') || '').trim().toLowerCase(),
    display: (searchRoot.getAttribute('data-user-display') || '').trim().toLowerCase(),
    email: (searchRoot.getAttribute('data-user-email') || '').trim().toLowerCase(),
  };
  const LIST_SORT_KEYS = new Set(['name', 'match', 'items', 'modified', 'modified_by', 'created_by']);
  const LIST_SORT_DEFAULT_DIR = {
    name: 'asc',
    match: 'desc',
    items: 'desc',
    modified: 'desc',
    modified_by: 'asc',
    created_by: 'asc',
  };
  const emptyListFilters = () => ({
    name: '',
    match: '',
    items: '',
    modified: '',
    modified_by: '',
    created_by: '',
  });

  const input = document.getElementById('sharepoint-search-input');
  const clearBtn = document.getElementById('sharepoint-search-clear');
  const controls = document.getElementById('sharepoint-search-controls');
  const advancedRoot = document.getElementById('sharepoint-search-advanced');
  const advancedToggle = document.getElementById('sharepoint-advanced-toggle');
  const wordModeGroup = document.getElementById('sharepoint-word-mode');
  const matchCluster = document.getElementById('sharepoint-match-cluster');
  const matchScopeGroup = document.getElementById('sharepoint-match-scope');
  const typeChipsRoot = document.getElementById('sharepoint-type-chips');
  const fuzzyToggle = document.getElementById('sharepoint-fuzzy-toggle');
  const deepToggle = document.getElementById('sharepoint-deep-toggle');
  const favoritesToggle = document.getElementById('sharepoint-favorites-toggle');
  const archivedToggle = document.getElementById('sharepoint-archived-toggle');
  const suggestToggle = document.getElementById('sharepoint-suggest-toggle');
  const refineInput = document.getElementById('sharepoint-refine-input');
  const refineClear = document.getElementById('sharepoint-refine-clear');
  const suggestEl = document.getElementById('sharepoint-search-suggest');
  const datePresetEl = document.getElementById('sharepoint-date-preset');
  const dateFromEl = document.getElementById('sharepoint-date-from');
  const dateToEl = document.getElementById('sharepoint-date-to');
  const dateCustomWrap = document.getElementById('sharepoint-date-custom-wrap');
  const dateCustomToWrap = document.getElementById('sharepoint-date-custom-to-wrap');
  const personFilterEl = document.getElementById('sharepoint-person-filter');
  const presenceFilterEl = document.getElementById('sharepoint-presence-filter');
  const presenceWrap = document.getElementById('sharepoint-presence-wrap');
  const missingWrap = document.getElementById('sharepoint-missing-wrap');
  const missingSourceEl = document.getElementById('sharepoint-missing-source');
  const hasFilterEl = document.getElementById('sharepoint-has-filter');
  const lacksFilterEl = document.getElementById('sharepoint-lacks-filter');
  const saveSearchBtn = document.getElementById('sharepoint-save-search');
  const exportCsvBtn = document.getElementById('sharepoint-export-csv');
  const savedRoot = document.getElementById('sharepoint-saved-searches');
  const savedChips = document.getElementById('sharepoint-saved-chips');
  const statsEl = document.getElementById('sharepoint-search-stats');
  const metaEl = document.getElementById('sharepoint-catalog-meta');
  const resultCountEl = document.getElementById('sharepoint-result-count');
  const paginationControls = document.getElementById('sharepoint-pagination-controls');
  const perPageSelect = document.getElementById('sharepoint-per-page');
  const form = document.getElementById('sharepoint-search-form');
  const recentRoot = document.getElementById('sharepoint-recent-searches');
  const recentChips = document.getElementById('sharepoint-recent-chips');
  const recentClearBtn = document.getElementById('sharepoint-recent-clear');
  const headingEl = document.getElementById('sharepoint-search-heading');
  const scopesRoot = document.getElementById('sharepoint-search-scopes');
  const compareOpenBtn = document.getElementById('sharepoint-compare-open');
  const compareClearBtn = document.getElementById('sharepoint-compare-clear');
  const compareHintEl = document.getElementById('sharepoint-compare-hint');
  const listTable = document.getElementById('sharepoint-projects-table');
  const resultCard = document.getElementById('sharepoint-table-card');
  const listFilterRow = document.getElementById('sharepoint-table-filters');
  const listFilterToggle = document.getElementById('sharepoint-filters-toggle');
  const listFilterClear = document.getElementById('sharepoint-filters-clear');
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

  const readCsvParam = (value, allowed) => {
    const parts = String(value || '')
      .split(',')
      .map((part) => part.trim().toLowerCase())
      .filter(Boolean);
    if (!allowed) return parts;
    return parts.filter((part) => allowed.has(part));
  };

  const readUrlSearchState = () => {
    const params = new URLSearchParams(window.location.search);
    const matchScopeRaw = (params.get('scope') || 'all').trim().toLowerCase();
    const matchScope = MATCH_SCOPES.has(matchScopeRaw) ? matchScopeRaw : 'all';
    const datePresetRaw = (params.get('date') || '').trim().toLowerCase();
    const datePreset = DATE_PRESETS.has(datePresetRaw) ? datePresetRaw : '';
    const presenceRaw = (params.get('presence') || 'any').trim().toLowerCase();
    const presence = PRESENCE_MODES.has(presenceRaw) ? presenceRaw : 'any';
    const fuzzyParam = params.get('fuzzy');
    const deepParam = params.get('deep');
    const archivedParam = params.get('archived');
    const modeParam = (params.get('mode') || '').trim().toLowerCase();
    return {
      refine: (params.get('refine') || '').trim(),
      matchScope,
      types: readCsvParam(params.get('type'), TYPE_CHIP_KEYS),
      datePreset,
      dateFrom: (params.get('from') || '').trim(),
      dateTo: (params.get('to') || '').trim(),
      who: (params.get('who') || '').trim(),
      presence,
      missingSource: (params.get('missing') || '').trim(),
      has: readCsvParam(params.get('has'), HAS_LACK_KEYS)[0] || '',
      lacks: readCsvParam(params.get('lacks'), HAS_LACK_KEYS)[0] || '',
      fuzzy:
        fuzzyParam === null
          ? localStorage.getItem(STORAGE.fuzzy) === '1'
          : fuzzyParam === '1' || fuzzyParam === 'true',
      deep:
        deepParam === null
          ? localStorage.getItem(STORAGE.deep) !== '0'
          : deepParam !== '0' && deepParam !== 'false',
      archived:
        archivedParam === null
          ? localStorage.getItem(STORAGE.archived) === '1'
          : archivedParam === '1' || archivedParam === 'true',
      wordMode:
        modeParam === 'or' || modeParam === 'and'
          ? modeParam
          : localStorage.getItem(STORAGE.wordMode) === 'or'
            ? 'or'
            : 'and',
      page: Math.max(1, parseInt(params.get('page') || '1', 10) || 1),
    };
  };

  const urlSearchState = readUrlSearchState();

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
    refine: urlSearchState.refine,
    wordMode: urlSearchState.wordMode,
    fuzzy: urlSearchState.fuzzy,
    deep: urlSearchState.deep,
    showArchived: catalogCanArchive() && !!urlSearchState.archived,
    showFavorites: (() => {
      if (publicShare || !favoritesToggle) return false;
      try {
        return localStorage.getItem(STORAGE.favorites) === '1';
      } catch {
        return false;
      }
    })(),
    favoriteCount: Number(favoritesToggle?.getAttribute('data-favorite-count') || 0) || 0,
    favoriteSources: new Set(),
    suggestEnabled: (() => {
      try {
        return localStorage.getItem(STORAGE.suggest) === '1';
      } catch {
        return false;
      }
    })(),
    matchScope: urlSearchState.matchScope,
    types: urlSearchState.types,
    datePreset: urlSearchState.datePreset,
    dateFrom: urlSearchState.dateFrom,
    dateTo: urlSearchState.dateTo,
    who: urlSearchState.who,
    presence: urlSearchState.presence,
    missingSource: urlSearchState.missingSource || (missingSourceEl?.value || ''),
    has: urlSearchState.has,
    lacks: urlSearchState.lacks,
    tagFilter: '',
    allTags: [],
    canEditTags: catalogCanEditTags(),
    canArchive: catalogCanArchive(),
    page: urlSearchState.page || 1,
    perPage: Number(searchRoot.dataset.perPage || perPageSelect?.value || 25) || 25,
    ready: false,
    loadingIndex: false,
    sortKey: 'name',
    sortDir: 'asc',
    userSort: false,
    filters: emptyListFilters(),
    filtersOpen: (() => {
      try {
        return localStorage.getItem(STORAGE.listFilters) !== '0';
      } catch {
        return true;
      }
    })(),
    suggestOpen: false,
    suggestIndex: -1,
    suggestItems: [],
    advancedOpen: (() => {
      try {
        if (localStorage.getItem(STORAGE.advancedOpen) === '1') return true;
      } catch {
        /* ignore */
      }
      return !!(
        urlSearchState.datePreset ||
        urlSearchState.who ||
        (urlSearchState.presence && urlSearchState.presence !== 'any') ||
        urlSearchState.has ||
        urlSearchState.lacks ||
        (urlSearchState.matchScope && urlSearchState.matchScope !== 'all')
      );
    })(),
    /** @type {Record<string, array>} Prepared search projects keyed by source_key (for peer hit counts). */
    indexBySource: {},
  };
  catalogSearchState = state;

  /** @type {AbortController|null} */
  let peerIndexAbort = null;
  let peerIndexRequestKey = '';

  const updateFavoriteToggleText = () => {
    if (!favoritesToggle) return;
    const count = Math.max(0, Number(state.favoriteCount) || 0);
    state.favoriteCount = count;
    favoritesToggle.setAttribute('data-favorite-count', String(count));
    favoritesToggle.textContent = `★ Fav (${count})`;
  };

  const bumpFavoriteCount = (favorited) => {
    state.favoriteCount = Math.max(0, (Number(state.favoriteCount) || 0) + (favorited ? 1 : -1));
    updateFavoriteToggleText();
  };

  const updateScopeFavoriteStars = () => {
    const scopesRoot = document.getElementById('sharepoint-search-scopes');
    if (!scopesRoot) return;
    const favorites = state.favoriteSources instanceof Set ? state.favoriteSources : new Set();
    scopesRoot.querySelectorAll('.sharepoint-scope-chip[data-source-key]').forEach((chip) => {
      const key = String(chip.getAttribute('data-source-key') || '');
      const isFav = key !== '' && favorites.has(key);
      chip.classList.toggle('is-favorite', isFav);
      chip.setAttribute('data-favorited', isFav ? '1' : '0');
      let star = chip.querySelector('.sharepoint-scope-favorite');
      if (!star) {
        star = document.createElement('span');
        star.className = 'sharepoint-scope-favorite';
        star.textContent = '★';
        star.title = 'Favorite catalog';
        star.setAttribute('aria-label', 'Favorite catalog');
        const label = chip.querySelector('.sharepoint-scope-chip-main');
        const hitCount = chip.querySelector('.sharepoint-scope-hit-count');
        if (hitCount) {
          hitCount.before(star);
        } else {
          label?.appendChild(star);
        }
      }
      star.hidden = !isFav;
    });
  };

  const setFavoriteSources = (keys) => {
    state.favoriteSources = new Set(
      (Array.isArray(keys) ? keys : []).map((key) => String(key || '').trim()).filter(Boolean)
    );
    updateScopeFavoriteStars();
  };

  const syncSourceFavorite = (sourceKey, favorited) => {
    const key = String(sourceKey || '').trim();
    if (!key) return;
    if (!(state.favoriteSources instanceof Set)) {
      state.favoriteSources = new Set();
    }
    if (favorited) state.favoriteSources.add(key);
    else state.favoriteSources.delete(key);
    updateScopeFavoriteStars();
  };

  window.RiskRegisterSharePoint = Object.assign(window.RiskRegisterSharePoint || {}, {
    syncSourceFavorite,
  });

  // Seed scope stars from server-rendered chips (data-favorited="1").
  (() => {
    const seeded = [];
    document.querySelectorAll('#sharepoint-search-scopes .sharepoint-scope-chip[data-favorited="1"]').forEach((chip) => {
      const key = String(chip.getAttribute('data-source-key') || '').trim();
      if (key) seeded.push(key);
    });
    if (seeded.length) setFavoriteSources(seeded);
    updateFavoriteToggleText();
  })();

  const projectEntries = (project) => {
    if (!Array.isArray(project?._entriesAll)) {
      const files = Array.isArray(project.files) ? project.files : [];
      const folders = Array.isArray(project.folders) ? project.folders : [];
      const out = [];
      files.forEach((item) => {
        const name = String(item?.name || '').trim();
        if (!name) return;
        const path = String(item?.path || name).trim() || name;
        out.push({
          kind: 'file',
          name,
          path,
          hay: `${name}\n${path}`.toLowerCase(),
          archived: !!item?.archived,
        });
      });
      folders.forEach((item) => {
        const name = String(item?.name || '').trim();
        if (!name) return;
        const path = String(item?.path || name).trim() || name;
        out.push({
          kind: 'folder',
          name,
          path,
          hay: `${name}\n${path}`.toLowerCase(),
          archived: !!item?.archived,
        });
      });
      if (!out.length) {
        (project.names || []).forEach((name) => {
          const label = String(name || '').trim();
          if (!label || label.toLowerCase() === String(project.project_name || '').trim().toLowerCase()) return;
          out.push({ kind: 'file', name: label, path: label, hay: label.toLowerCase(), archived: false });
        });
      }
      project._entriesAll = out;
    }
    const all = project._entriesAll;
    if (catalogShowArchived() || state.showArchived) return all;
    return all.filter((entry) => !entry.archived);
  };

  const parseProjectDate = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return null;
    const ms = Date.parse(raw);
    return Number.isFinite(ms) ? ms : null;
  };

  const freshnessBucket = (lastModifiedMs) => {
    if (lastModifiedMs == null) return 'unknown';
    const age = Date.now() - lastModifiedMs;
    if (age <= 30 * DAY_MS) return 'fresh';
    if (age > 90 * DAY_MS) return 'stale';
    return 'normal';
  };

  const formatAgeLabel = (lastModifiedMs) => {
    if (lastModifiedMs == null) return '';
    const age = Math.max(0, Date.now() - lastModifiedMs);
    if (age < 60 * 1000) return 'just now';
    if (age < 60 * 60 * 1000) {
      const n = Math.max(1, Math.round(age / 60000));
      return n === 1 ? '1 min old' : `${n} mins old`;
    }
    if (age < DAY_MS) {
      const n = Math.max(1, Math.round(age / (60 * 60 * 1000)));
      return n === 1 ? '1 hour old' : `${n} hours old`;
    }
    if (age < 45 * DAY_MS) {
      const n = Math.max(1, Math.round(age / DAY_MS));
      return n === 1 ? '1 day old' : `${n} days old`;
    }
    if (age < 365 * DAY_MS) {
      const n = Math.max(1, Math.round(age / (30 * DAY_MS)));
      return n === 1 ? '1 month old' : `${n} months old`;
    }
    const n = Math.max(1, Math.round(age / (365 * DAY_MS)));
    return n === 1 ? '1 year old' : `${n} years old`;
  };

  const prepareSearchProject = (project) => {
    const entries = projectEntries(project);
    project._entries = entries;
    const exts = new Set();
    let hasDrawingsFolder = false;
    entries.forEach((entry) => {
      if (entry.kind === 'file') {
        const ext = fileExtension(entry.name);
        if (ext) exts.add(ext);
      }
      const hay = `${entry.name}\n${entry.path}`.toLowerCase();
      if (/\bdrawings?\b/.test(hay)) hasDrawingsFolder = true;
    });
    project._exts = exts;
    project._hasDrawingsFolder = hasDrawingsFolder;
    project._hasPdf = exts.has('pdf');
    project._hasCad = [...exts].some((ext) => CAD_EXTS.has(ext));
    project._hasVisio = [...exts].some((ext) => VISIO_EXTS.has(ext));
    project._hasImages = [...exts].some((ext) => IMAGE_EXTS.has(ext));
    project._hasDrawings = hasDrawingsFolder || project._hasCad;
    project._hasSubfolders =
      (Array.isArray(project.folders) ? project.folders.length : 0) > 0 ||
      entries.some((entry) => entry.kind === 'folder');
    project._isEmpty = Number(project.file_count || 0) === 0;
    const modifiedMs = parseProjectDate(project.last_modified);
    project._modifiedMs = modifiedMs;
    project._freshness = freshnessBucket(modifiedMs);
    project._isStale = project._freshness === 'stale' || modifiedMs == null;
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
    project._hayNames = [project.project_name, project.source_title]
      .map((value) => String(value || '').toLowerCase())
      .filter(Boolean)
      .join('\n');
    project._hayPeople = [project.modified_by, project.person]
      .map((value) => String(value || '').toLowerCase())
      .filter(Boolean)
      .join('\n');
    project._hayFiles = entries.map((entry) => `${entry.name}\n${entry.path}`.toLowerCase()).join('\n');
    const tagBits = [];
    const tagFields = [];
    const seenTagLabels = new Set();
    const pushTag = (tag) => {
      const label = String(tag?.label || '').trim();
      const slug = String(tag?.slug || '').trim().toLowerCase();
      if (label) {
        const key = label.toLowerCase();
        tagBits.push(key);
        if (!seenTagLabels.has(key)) {
          seenTagLabels.add(key);
          tagFields.push({ text: label, sourceLabel: 'Tag', sourceName: label });
        }
      }
      if (slug) tagBits.push(slug);
    };
    normalizeTagList(project.tags).forEach(pushTag);
    (Array.isArray(project.files) ? project.files : []).forEach((file) => {
      normalizeTagList(file?.tags).forEach(pushTag);
    });
    (Array.isArray(project.folders) ? project.folders : []).forEach((folder) => {
      normalizeTagList(folder?.tags).forEach(pushTag);
    });
    project._hayTags = [...new Set(tagBits.filter(Boolean))].join('\n');
    project._tagFields = tagFields;
    if (Fuzzy?.tokenizeSearchText) {
      project._tokensNames = Fuzzy.tokenizeSearchText(project._hayNames || '');
      project._tokensPeople = Fuzzy.tokenizeSearchText(project._hayPeople || '');
      project._tokensFiles = Fuzzy.tokenizeSearchText(project._hayFiles || '');
      project._tokensShallow = Fuzzy.tokenizeSearchText(project._hayShallow || '');
      project._tokensDeep = Fuzzy.tokenizeSearchText(project._hayDeep || '');
      project._tokensTags = Fuzzy.tokenizeSearchText(project._hayTags || '');
    } else {
      project._tokensNames = [];
      project._tokensPeople = [];
      project._tokensFiles = [];
      project._tokensShallow = [];
      project._tokensDeep = [];
      project._tokensTags = [];
    }
    return project;
  };

  const withTagHay = (hay, project) => {
    const tags = String(project?._hayTags || '');
    const base = String(hay || '');
    if (!tags) return base;
    if (!base) return tags;
    return `${base}\n${tags}`;
  };

  const withProjectTagTokens = (tokens, project) => {
    const tags = Array.isArray(project?._tokensTags) ? project._tokensTags : [];
    if (!tags.length) return Array.isArray(tokens) ? tokens : [];
    return (Array.isArray(tokens) ? tokens : []).concat(tags);
  };

  const withProjectTagFields = (fields, project) => {
    const tags = Array.isArray(project?._tagFields) ? project._tagFields : [];
    if (!tags.length) return fields;
    return (Array.isArray(fields) ? fields : []).concat(tags);
  };

  const haystackHasWords = (hay, words, mode) => {
    if (!hay) return false;
    if (!words.length) return true;
    if (mode === 'or') return words.some((word) => hay.includes(word));
    return words.every((word) => hay.includes(word));
  };

  const haystackHasPhrases = (hay, phrases) => {
    if (!phrases.length) return true;
    return phrases.every((phrase) => hay.includes(phrase));
  };

  const haystackHasExcludes = (hay, excludes) => {
    if (!excludes.length) return false;
    return excludes.some((token) => hay.includes(token));
  };

  const effectiveDeep = (matchScope = state.matchScope, deep = state.deep) => {
    if (matchScope === 'files') return true;
    if (matchScope === 'names' || matchScope === 'people') return false;
    return !!deep;
  };

  const scopedHaystack = (project, matchScope = state.matchScope, deep = state.deep) => {
    let hay = '';
    if (matchScope === 'names') hay = project._hayNames || '';
    else if (matchScope === 'people') hay = project._hayPeople || '';
    else if (matchScope === 'files') hay = project._hayFiles || '';
    else hay = (effectiveDeep(matchScope, deep) ? project._hayDeep : project._hayShallow) || '';
    return withTagHay(hay, project);
  };

  const cheapProjectMatch = (project, words, mode, deep, matchScope = state.matchScope) => {
    const hay = scopedHaystack(project, matchScope, deep);
    if (!words.length) return { matched: true, score: 100, kind: 'exact' };
    if (!haystackHasWords(hay, words, mode)) return { matched: false, score: 0, kind: 'none' };
    const name = String(project.project_name || '').toLowerCase();
    const nameHit =
      matchScope !== 'files' && matchScope !== 'people'
        ? mode === 'or'
          ? words.some((word) => name.includes(word))
          : words.every((word) => name.includes(word))
        : false;
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
    const tagHay = String(project._hayTags || '');
    if (tagHay && haystackHasWords(tagHay, words, mode)) {
      const snippet =
        tagHay
          .split('\n')
          .find((line) => words.some((word) => line.includes(String(word || '').toLowerCase()))) || '';
      return {
        matched: true,
        score: 90,
        kind: 'contains',
        source: 'Tag',
        sourceName: snippet,
        snippet,
      };
    }
    return {
      matched: true,
      score: 86,
      kind: 'contains',
      source: matchScope === 'people' ? 'Person' : effectiveDeep(matchScope, deep) ? 'File' : 'Catalog',
    };
  };

  const projectFields = (project, deep = true, matchScope = 'all') => {
    if (matchScope === 'names') {
      return withProjectTagFields(
        [
          { text: project.project_name, sourceLabel: 'Project' },
          { text: project.source_title, sourceLabel: 'Catalog' },
        ],
        project
      );
    }
    if (matchScope === 'people') {
      return withProjectTagFields(
        [
          { text: project.modified_by, sourceLabel: 'Modified By' },
          { text: project.person, sourceLabel: 'Created By' },
        ],
        project
      );
    }
    if (matchScope === 'files') {
      const fields = [];
      projectEntries(project).forEach((entry) => {
        fields.push({
          text: entry.name,
          sourceLabel: entry.kind === 'folder' ? 'Folder' : 'File',
          sourceName: entry.name,
        });
        if (entry.path && entry.path !== entry.name) {
          fields.push({ text: entry.path, sourceLabel: 'Path', sourceName: entry.path });
        }
      });
      return withProjectTagFields(fields, project);
    }
    const fields = [
      { text: project.project_name, sourceLabel: 'Project' },
      { text: project.source_title, sourceLabel: 'Catalog' },
      { text: project.modified_by, sourceLabel: 'Modified By' },
      { text: project.person, sourceLabel: 'Created By' },
    ];
    if (!deep) return withProjectTagFields(fields, project);
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
      return withProjectTagFields(fields, project);
    }
    (project.names || []).forEach((name) => {
      fields.push({ text: name, sourceLabel: 'File', sourceName: name });
    });
    (project.paths || []).forEach((path) => {
      fields.push({ text: path, sourceLabel: 'Path', sourceName: path });
    });
    return withProjectTagFields(fields, project);
  };

  const projectTokens = (project, deep, matchScope = 'all') => {
    let tokens;
    if (matchScope === 'names') {
      tokens = project._tokensNames || Fuzzy.tokenizeSearchText?.(project._hayNames || '') || [];
    } else if (matchScope === 'people') {
      tokens = project._tokensPeople || Fuzzy.tokenizeSearchText?.(project._hayPeople || '') || [];
    } else if (matchScope === 'files') {
      tokens = project._tokensFiles || Fuzzy.tokenizeSearchText?.(project._hayFiles || '') || [];
    } else if (deep) {
      tokens = project._tokensDeep || Fuzzy.tokenizeSearchText?.(project._hayDeep || '') || [];
    } else {
      tokens = project._tokensShallow || Fuzzy.tokenizeSearchText?.(project._hayShallow || '') || [];
    }
    return withProjectTagTokens(tokens, project);
  };

  const attachProjectMatchMeta = (project, match, words, deep, matchScope = 'all') => {
    if (!match?.matched) return match;
    const token = String(match.token || '').toLowerCase();
    const longestWord = [...words].sort((a, b) => String(b || '').length - String(a || '').length)[0] || '';
    const prefer = token || String(longestWord || '').toLowerCase();
    const needles = [...new Set([prefer, token, ...words.map((word) => String(word || '').toLowerCase())].filter(Boolean))];
    const preferred = [];
    const fallback = [];
    const consider = (text, sourceLabel, sourceName) => {
      const hay = String(text || '').toLowerCase();
      if (!hay) return;
      const row = { text, sourceLabel, sourceName };
      if (prefer && hay.includes(prefer)) preferred.push(row);
      else if (needles.some((needle) => hay.includes(needle))) fallback.push(row);
    };

    if (matchScope === 'names') {
      consider(project.project_name, 'Project');
      consider(project.source_title, 'Catalog');
    } else if (matchScope === 'people') {
      consider(project.modified_by, 'Modified By');
      consider(project.person, 'Created By');
    } else if (matchScope === 'files') {
      projectEntries(project).forEach((entry) => {
        consider(entry.name, entry.kind === 'folder' ? 'Folder' : 'File', entry.name);
        if (entry.path && entry.path !== entry.name) consider(entry.path, 'Path', entry.path);
      });
    } else {
      consider(project.project_name, 'Project');
      consider(project.source_title, 'Catalog');
      consider(project.modified_by, 'Modified By');
      consider(project.person, 'Created By');
      if (deep) {
        projectEntries(project).forEach((entry) => {
          consider(entry.name, entry.kind === 'folder' ? 'Folder' : 'File', entry.name);
          if (entry.path && entry.path !== entry.name) consider(entry.path, 'Path', entry.path);
        });
      }
    }
    (project._tagFields || []).forEach((field) => {
      consider(field.text, field.sourceLabel || 'Tag', field.sourceName);
    });

    const first = (preferred.length ? preferred : fallback)[0];
    const extraCount = Math.max(0, (preferred.length ? preferred : fallback).length - 1);
    const snippetNeedle = token || words[0] || '';
    return {
      ...match,
      source: first?.sourceLabel || match.source,
      sourceName: first?.sourceName || match.sourceName,
      snippet: first ? Fuzzy.excerptAroundMatch?.(first.text, snippetNeedle) : match.snippet,
      extraCount,
    };
  };

  const scoreProject = (project, words, mode, fuzzy, deep = true, matchScope = state.matchScope) => {
    if (!words.length) return { matched: true, score: 100, kind: 'exact' };
    const useDeep = effectiveDeep(matchScope, deep);
    const hay = scopedHaystack(project, matchScope, useDeep);
    const needsFuzzy =
      Fuzzy.queryNeedsFuzzy?.(words, fuzzy) ?? !!(fuzzy && words.some((word) => String(word || '').length >= 3));

    if (mode === 'and') {
      for (let i = 0; i < words.length; i++) {
        const word = String(words[i] || '');
        if (word.length < 3 && !hay.includes(word)) {
          return { matched: false, score: 0, kind: 'none' };
        }
      }
    }

    const cheap = cheapProjectMatch(project, words, mode, useDeep, matchScope);
    if (!needsFuzzy) return cheap;

    const tokens = projectTokens(project, useDeep, matchScope);
    const scored = Fuzzy.scoreTokensAgainstWords
      ? Fuzzy.scoreTokensAgainstWords(tokens, words, mode, true)
      : Fuzzy.scoreLabeledFieldsAgainstWords(projectFields(project, useDeep, matchScope), words, mode, true);
    if (!scored?.matched) return scored || { matched: false, score: 0, kind: 'none' };
    return attachProjectMatchMeta(project, scored, words, useDeep, matchScope);
  };

  const scoreDeepEntry = (entry, words, mode, fuzzy) => {
    const hay = entry.hay || `${entry.name}\n${entry.path}`.toLowerCase();
    const nameHay = String(entry.name || '').toLowerCase();
    const tagged = (match) =>
      match?.matched
        ? {
            ...match,
            source: entry.kind === 'folder' ? 'Folder' : 'File',
            sourceName: entry.name,
            snippet: Fuzzy.excerptAroundMatch?.(entry.name, match.token || words[0] || '') || entry.name,
          }
        : null;

    if (mode === 'and') {
      for (let i = 0; i < words.length; i++) {
        const word = String(words[i] || '').toLowerCase();
        if (word.length < 3 && !hay.includes(word)) return null;
        if (!fuzzy && !hay.includes(word)) return null;
      }
      if (words.every((word) => hay.includes(String(word || '').toLowerCase()))) {
        const inName = words.every((word) => nameHay.includes(String(word || '').toLowerCase()));
        return tagged({ matched: true, score: inName ? 96 : 90, kind: 'contains' });
      }
      if (!fuzzy) return null;
    } else if (words.some((word) => hay.includes(String(word || '').toLowerCase()))) {
      const inName = words.some((word) => nameHay.includes(String(word || '').toLowerCase()));
      return tagged({ matched: true, score: inName ? 94 : 86, kind: 'contains' });
    } else if (!fuzzy) {
      return null;
    }

    if (Fuzzy.scoreHayAgainstWords) {
      return tagged(Fuzzy.scoreHayAgainstWords(hay, words, mode, true));
    }
    return tagged(
      Fuzzy.scoreLabeledFieldsAgainstWords(
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
        true
      )
    );
  };

  const collectDeepHits = (project, words, mode, fuzzy, limit = 4) => {
    if (!words.length) return { hits: [], total: 0 };
    const entries = projectEntries(project);
    const cap = Number.isFinite(limit) && limit >= 0 ? limit : Infinity;
    const hits = [];
    let total = 0;
    const countOnly = cap === 0;
    const remember = (entry, match) => {
      total += 1;
      if (countOnly) return;
      const row = { ...entry, match };
      if (!Number.isFinite(cap)) {
        hits.push(row);
        return;
      }
      if (hits.length < cap) {
        hits.push(row);
        if (hits.length === cap) hits.sort((a, b) => (b.match?.score || 0) - (a.match?.score || 0));
        return;
      }
      if ((match?.score || 0) <= (hits[hits.length - 1].match?.score || 0)) return;
      hits[hits.length - 1] = row;
      hits.sort((a, b) => (b.match?.score || 0) - (a.match?.score || 0));
    };

    for (let i = 0; i < entries.length; i++) {
      const match = scoreDeepEntry(entries[i], words, mode, fuzzy);
      if (match) remember(entries[i], match);
    }
    if (!Number.isFinite(cap)) {
      hits.sort((a, b) => (b.match?.score || 0) - (a.match?.score || 0));
    }
    return { hits, total };
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

  const scoreBadgeHtml = (match, project = null) => {
    const freshness = project?._freshness;
    const ageLabel = formatAgeLabel(project?._modifiedMs);
    const ageTitle =
      project?._modifiedMs != null
        ? `Last modified ${formatModified(project.last_modified)}${ageLabel ? ` · ${ageLabel}` : ''}`
        : 'No last-modified date';
    const freshnessBadge =
      freshness === 'fresh'
        ? '<span class="sp-fresh-badge sp-fresh-badge--fresh" title="Modified within 30 days">Fresh</span>'
        : freshness === 'stale'
          ? `<span class="sp-fresh-badge sp-fresh-badge--stale sp-fresh-badge--age" title="${escapeHtml(ageTitle)}">${escapeHtml(
              ageLabel || 'No date'
            )}</span>`
          : '';
    if (!match?.matched) {
      return `<span class="sp-match-placeholder">—</span>${freshnessBadge}`;
    }
    const label = Fuzzy.matchKindLabel(match.kind);
    let reason = Fuzzy.formatMatchReason(match);
    if (match.tagMatched) {
      const tagNames = (match.matchedTags || []).slice(0, 3);
      const tagReason = tagNames.length
        ? `Tag${tagNames.length === 1 ? '' : 's'} “${tagNames.join('”, “')}”`
        : 'Tag';
      if (!reason) reason = tagReason;
      else if (match.source !== 'Tag' && !/\bTag/i.test(reason)) reason = `${reason} · ${tagReason}`;
    }
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
      ${freshnessBadge}
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
        card.querySelector('.sharepoint-source-card-tools') ||
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

  const syncSelectedProjectCount = () => {
    const countEl = document.getElementById('sharepoint-selected-project-count');
    if (!countEl) return;
    const count = Math.max(0, Number(state.projectCount) || 0);
    countEl.textContent = `${count} project${count === 1 ? '' : 's'}`;
  };

  const syncScopeChips = () => {
    if (!scopesRoot) return;
    scopesRoot.querySelectorAll('.sharepoint-scope-check').forEach((input) => {
      const checked = state.scopeKeys.includes(input.value);
      input.checked = checked;
      input.closest('.sharepoint-scope-chip')?.classList.toggle('is-active', checked);
    });
    syncCatalogHitBadges();
  };

  const hitCountSearchActive = () => !!(String(state.query || '').trim() || String(state.refine || '').trim());

  const mergeProjectsIntoIndexCache = (projects) => {
    if (!Array.isArray(projects) || !projects.length) return;
    const grouped = {};
    projects.forEach((project) => {
      const key = String(project?.source_key || '');
      if (!key) return;
      if (!grouped[key]) grouped[key] = [];
      grouped[key].push(project);
    });
    Object.entries(grouped).forEach(([key, rows]) => {
      state.indexBySource[key] = rows;
    });
  };

  const ensurePeerIndexes = () => {
    if (availableSources.length <= 1) return;
    if (!hitCountSearchActive()) {
      if (peerIndexAbort) {
        try {
          peerIndexAbort.abort();
        } catch {
          /* ignore */
        }
        peerIndexAbort = null;
        peerIndexRequestKey = '';
      }
      return;
    }
    const missing = availableSources
      .map((src) => String(src.source_key || ''))
      .filter((key) => key && !Object.prototype.hasOwnProperty.call(state.indexBySource, key));
    if (!missing.length) {
      syncCatalogHitBadges();
      return;
    }

    const requestKey = missing.join('\u0000');
    if (peerIndexAbort && peerIndexRequestKey === requestKey) return;
    if (peerIndexAbort) {
      try {
        peerIndexAbort.abort();
      } catch {
        /* ignore */
      }
    }
    const controller = new AbortController();
    peerIndexAbort = controller;
    peerIndexRequestKey = requestKey;
    const fetchGen = (state._peerIndexGen = (state._peerIndexGen || 0) + 1);

    fetch(catalogApiUrl('search_index', { sources: missing.join(',') }), {
      credentials: 'same-origin',
      cache: 'no-store',
      signal: controller.signal,
      headers: { Accept: 'application/json', 'Cache-Control': 'no-cache' },
    })
      .then((response) => response.json())
      .then((payload) => {
        if (fetchGen !== state._peerIndexGen) return;
        if (!payload?.ok || !Array.isArray(payload.projects)) return;
        mergeProjectsIntoIndexCache(payload.projects.map(prepareSearchProject));
        // Ensure missing keys are marked present even if empty, so we do not refetch forever.
        missing.forEach((key) => {
          if (!Object.prototype.hasOwnProperty.call(state.indexBySource, key)) {
            state.indexBySource[key] = [];
          }
        });
        syncCatalogHitBadges();
      })
      .catch((error) => {
        if (error?.name === 'AbortError') return;
        /* Peer index is optional — leave badges for cached catalogs only. */
      })
      .finally(() => {
        if (peerIndexAbort === controller) {
          peerIndexAbort = null;
          peerIndexRequestKey = '';
        }
      });
  };

  const catalogHitCounts = () => {
    /** @type {Record<string, number>} */
    const counts = {};
    availableSources.forEach((src) => {
      const key = String(src.source_key || '');
      if (key) counts[key] = 0;
    });
    if (!hitCountSearchActive()) return counts;

    const parsed = parseActiveQuery();
    const refineParsed = state.refine.trim()
      ? Fuzzy.parseCatalogQuery
        ? Fuzzy.parseCatalogQuery(state.refine)
        : { words: Fuzzy.getSearchWords(state.refine), phrases: [], excludes: [] }
      : null;

    Object.entries(state.indexBySource).forEach(([key, projects]) => {
      if (!Array.isArray(projects)) return;
      let n = 0;
      projects.forEach((project) => {
        if (!state.showArchived && project?.archived) return;
        if (!projectPassesQueryFilters(project, parsed)) return;
        const match = scoreParsedProject(project, parsed, refineParsed);
        if (match?.matched) n += 1;
      });
      counts[key] = n;
    });
    return counts;
  };

  const syncCatalogHitBadges = () => {
    if (!scopesRoot) return;
    const active = hitCountSearchActive();
    const counts = active ? catalogHitCounts() : {};
    scopesRoot.querySelectorAll('.sharepoint-scope-chip[data-source-key]').forEach((chip) => {
      const key = String(chip.getAttribute('data-source-key') || '');
      let badge = chip.querySelector('.sharepoint-scope-hit-count');
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'sharepoint-scope-hit-count';
        badge.hidden = true;
        badge.setAttribute('aria-hidden', 'true');
        const label = chip.querySelector('.sharepoint-scope-chip-main');
        label?.appendChild(badge);
      }
      const count = Number(counts[key] || 0);
      const show = active && count > 0;
      if (!show) {
        badge.hidden = true;
        badge.textContent = '';
        badge.removeAttribute('title');
        badge.setAttribute('aria-hidden', 'true');
        chip.classList.remove('has-hits');
        return;
      }
      badge.hidden = false;
      badge.textContent = String(count);
      badge.title = `${count} matching project${count === 1 ? '' : 's'} in this catalog`;
      badge.setAttribute('aria-hidden', 'false');
      chip.classList.toggle('has-hits', !chip.classList.contains('is-active'));
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
    bindQrButtons(tbody);
    tbody.querySelectorAll('.sp-favorite-project-btn').forEach((btn) => {
      btn.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (btn.disabled) return;
        const sourceKey = btn.getAttribute('data-source-key') || state.sourceKey;
        const projectName = btn.getAttribute('data-project-name') || '';
        const nextFavorited = btn.getAttribute('data-favorited') !== '1';
        try {
          await postFavorite({
            scope: 'project',
            sourceKey,
            projectName,
            favorited: nextFavorited,
          });
          const listProject = state.projects.find(
            (row) =>
              String(row.project_name || '') === projectName &&
              String(row.source_key || '') === sourceKey
          );
          if (listProject) listProject.favorited = nextFavorited;
          applyFavoriteButtonState(btn, nextFavorited);
          const row = btn.closest('.sharepoint-project-row');
          row?.classList.toggle('is-favorite', nextFavorited);
          const nameEl = row?.querySelector('.sp-file-name');
          if (nameEl) {
            let mark = nameEl.querySelector('.sp-project-fav-mark');
            if (nextFavorited) {
              if (!mark) {
                mark = document.createElement('span');
                mark.className = 'sp-project-fav-mark';
                mark.title = 'Favorite';
                mark.setAttribute('aria-label', 'Favorite');
                mark.textContent = '★';
                nameEl.appendChild(mark);
              }
            } else if (mark) {
              mark.remove();
            }
          }
          bumpFavoriteCount(nextFavorited);
          if (state.showFavorites && !nextFavorited) {
            applySearch({ resetPage: false });
          }
        } catch (error) {
          window.alert(error.message || 'Unable to update favorite.');
        }
      });
    });
    tbody.querySelectorAll('.sp-archive-project-btn').forEach((btn) => {
      btn.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (btn.disabled) return;
        const sourceKey = btn.getAttribute('data-source-key') || state.sourceKey;
        const projectName = btn.getAttribute('data-project-name') || '';
        const nextArchived = btn.getAttribute('data-archived') !== '1';
        try {
          await postArchive({
            scope: 'project',
            sourceKey,
            projectName,
            archived: nextArchived,
          });
          loadIndex();
        } catch (error) {
          window.alert(error.message || 'Unable to update archive.');
        }
      });
    });
  };

  const listFiltersActive = () =>
    Object.values(state.filters).some((value) => String(value || '').trim() !== '');

  const projectTypeLabel = (project) => {
    const meta = resolveProjectMeta(project);
    return meta.tone === 'folder' ? 'Folder' : String(meta.label || 'File');
  };

  const rowFilterHaystack = (row, key) => {
    const project = row.project || {};
    const folders = Number(project.folder_count || 0);
    const files = Number(project.file_count || 0);
    switch (key) {
      case 'name':
        return String(project.project_name || '');
      case 'match': {
        const match = row.match;
        return [
          projectTypeLabel(project),
          project.source_title,
          match?.kind,
          match?.score != null ? `${match.score}%` : '',
          Fuzzy.matchKindLabel?.(match?.kind) || '',
          match?.token,
        ].join(' ');
      }
      case 'items':
        return `${folders} folders ${files} files ${folders + files}`;
      case 'modified':
        return `${project.last_modified || ''} ${formatModified(project.last_modified)}`;
      case 'modified_by':
        return String(project.modified_by || '');
      case 'created_by':
        return String(project.person || '');
      default:
        return '';
    }
  };

  const rowPassesColumnFilters = (row) =>
    Object.entries(state.filters).every(([key, value]) => {
      const needle = String(value || '')
        .trim()
        .toLowerCase();
      if (!needle) return true;
      return rowFilterHaystack(row, key).toLowerCase().includes(needle);
    });

  const compareProjectRows = (a, b) => {
    const key = LIST_SORT_KEYS.has(state.sortKey) ? state.sortKey : 'name';
    const direction = state.sortDir === 'desc' ? -1 : 1;
    const pa = a.project || {};
    const pb = b.project || {};
    let cmp = 0;

    switch (key) {
      case 'match': {
        cmp = (a.match?.score || 0) - (b.match?.score || 0);
        if (cmp !== 0) break;
        cmp = projectTypeLabel(pa).localeCompare(projectTypeLabel(pb), undefined, { sensitivity: 'base' });
        if (cmp !== 0) break;
        cmp = String(pa.source_title || '').localeCompare(String(pb.source_title || ''), undefined, {
          sensitivity: 'base',
        });
        break;
      }
      case 'items': {
        cmp = Number(pa.folder_count || 0) + Number(pa.file_count || 0) - (Number(pb.folder_count || 0) + Number(pb.file_count || 0));
        if (cmp !== 0) break;
        cmp = Number(pa.file_count || 0) - Number(pb.file_count || 0);
        break;
      }
      case 'modified': {
        cmp = (parseItemDate(pa.last_modified)?.getTime() || 0) - (parseItemDate(pb.last_modified)?.getTime() || 0);
        break;
      }
      case 'modified_by':
        cmp = String(pa.modified_by || '').localeCompare(String(pb.modified_by || ''), undefined, {
          sensitivity: 'base',
        });
        break;
      case 'created_by':
        cmp = String(pa.person || '').localeCompare(String(pb.person || ''), undefined, { sensitivity: 'base' });
        break;
      case 'name':
      default:
        cmp = String(pa.project_name || '').localeCompare(String(pb.project_name || ''), undefined, {
          sensitivity: 'base',
          numeric: true,
        });
        break;
    }

    if (cmp === 0) {
      cmp = String(pa.project_name || '').localeCompare(String(pb.project_name || ''), undefined, {
        sensitivity: 'base',
        numeric: true,
      });
    }
    if (cmp === 0) {
      cmp = String(pa.source_title || '').localeCompare(String(pb.source_title || ''), undefined, {
        sensitivity: 'base',
      });
    }
    return cmp * direction;
  };

  const syncListSortHeaders = () => {
    listTable?.querySelectorAll('thead tr:first-child th.is-sortable').forEach((th) => {
      const key = th.getAttribute('data-sort') || '';
      const active = key === state.sortKey;
      th.classList.toggle('is-sorted-asc', active && state.sortDir === 'asc');
      th.classList.toggle('is-sorted-desc', active && state.sortDir === 'desc');
      th.setAttribute('aria-sort', active ? (state.sortDir === 'desc' ? 'descending' : 'ascending') : 'none');
    });
  };

  const syncListFilterUi = () => {
    const open = state.filtersOpen;
    if (listFilterRow) listFilterRow.hidden = !open;
    if (listFilterToggle) {
      listFilterToggle.classList.toggle('is-active', open);
      listFilterToggle.setAttribute('aria-pressed', open ? 'true' : 'false');
      listFilterToggle.textContent = open ? 'Hide filters' : 'Filters';
    }
    listFilterClear?.classList.toggle('is-hidden', !listFiltersActive());
    listFilterRow?.querySelectorAll('.sharepoint-col-filter[data-filter]').forEach((input) => {
      const key = input.getAttribute('data-filter') || '';
      const next = state.filters[key] || '';
      if (input.value !== next) input.value = next;
    });
  };

  const rememberDefaultSort = (searching) => {
    if (state.userSort) return;
    if (searching) {
      state.sortKey = 'match';
      state.sortDir = 'desc';
    } else {
      state.sortKey = 'name';
      state.sortDir = 'asc';
    }
  };

  const projectHasTrait = (project, trait) => {
    const key = String(trait || '').toLowerCase();
    if (key === 'pdf') return !!project._hasPdf;
    if (key === 'visio') return !!project._hasVisio;
    if (key === 'cad') return !!project._hasCad;
    if (key === 'drawings') return !!project._hasDrawings;
    if (key === 'images') return !!project._hasImages;
    if (key === 'folders') return !!project._hasSubfolders;
    if (key === 'empty') return !!project._isEmpty;
    if (key === 'stale') return !!project._isStale;
    if (TYPE_TRAIT_EXTS[key] && project._exts instanceof Set) {
      return [...TYPE_TRAIT_EXTS[key]].some((ext) => project._exts.has(ext));
    }
    if (key.startsWith('.') || /^[a-z0-9]+$/i.test(key)) {
      const ext = key.replace(/^\./, '');
      return project._exts instanceof Set && project._exts.has(ext);
    }
    return false;
  };

  const resolveMeAlias = (value) => {
    const raw = String(value || '').trim().toLowerCase();
    if (raw !== 'me') return raw;
    return currentUser.display || currentUser.name || currentUser.email || 'me';
  };

  const personMatchesValue = (project, value) => {
    const needle = resolveMeAlias(value);
    if (!needle) return true;
    const hay = `${project.modified_by || ''}\n${project.person || ''}`.toLowerCase();
    return hay.includes(needle);
  };

  const dateRangeBounds = () => {
    const preset = state.datePreset || '';
    if (!preset) return null;
    const now = new Date();
    if (preset === '7d') return { from: Date.now() - 7 * DAY_MS, to: null };
    if (preset === '30d') return { from: Date.now() - 30 * DAY_MS, to: null };
    if (preset === 'year') return { from: Date.UTC(now.getFullYear(), 0, 1), to: null };
    if (preset === 'custom') {
      const from = state.dateFrom ? Date.parse(`${state.dateFrom}T00:00:00`) : null;
      const to = state.dateTo ? Date.parse(`${state.dateTo}T23:59:59`) : null;
      if (!Number.isFinite(from) && !Number.isFinite(to)) return null;
      return {
        from: Number.isFinite(from) ? from : null,
        to: Number.isFinite(to) ? to : null,
      };
    }
    return null;
  };

  const projectPassesDate = (project) => {
    const bounds = dateRangeBounds();
    if (!bounds) return true;
    const ms = project._modifiedMs;
    if (ms == null) return false;
    if (bounds.from != null && ms < bounds.from) return false;
    if (bounds.to != null && ms > bounds.to) return false;
    return true;
  };

  const projectPassesPresence = (project, presence) => {
    if (state.presence === 'any' || state.scopeKeys.length < 2) return true;
    const name = String(project.project_name || '')
      .trim()
      .toLowerCase();
    const present = presence.get(name) || new Set();
    if (state.presence === 'all') {
      return state.scopeKeys.every((key) => present.has(key));
    }
    if (state.presence === 'only') {
      const focus = state.sourceKey || state.scopeKeys[0];
      if (!focus) return true;
      if (!present.has(focus)) return false;
      return state.scopeKeys.every((key) => key === focus || !present.has(key));
    }
    if (state.presence === 'missing') {
      const missingKey = state.missingSource || '';
      if (!missingKey) return true;
      return !present.has(missingKey);
    }
    return true;
  };

  const advancedFiltersActive = () =>
    state.types.length > 0 ||
    !!state.datePreset ||
    !!state.who ||
    (state.presence !== 'any' && state.scopeKeys.length > 1) ||
    !!state.has ||
    !!state.lacks ||
    !!state.tagFilter;

  const parseActiveQuery = () =>
    Fuzzy.parseCatalogQuery
      ? Fuzzy.parseCatalogQuery(state.query)
      : { words: Fuzzy.getSearchWords(state.query), phrases: [], excludes: [], extensions: [], types: [], person: '', modifiedBy: '', createdBy: '', paths: [], has: [], lacks: [], tags: [] };

  const scoreParsedProject = (project, parsed, refineParsed = null) => {
    const useDeep = effectiveDeep(state.matchScope, state.deep);
    const hay = scopedHaystack(project, state.matchScope, state.deep);
    const fullHay = withTagHay(project._hayDeep || hay, project);

    if (parsed.phrases.length && !haystackHasPhrases(hay, parsed.phrases)) {
      return { matched: false, score: 0, kind: 'none' };
    }
    if (haystackHasExcludes(fullHay, parsed.excludes)) {
      return { matched: false, score: 0, kind: 'none' };
    }
    if (parsed.paths.length) {
      const pathHay = project._hayFiles || fullHay;
      if (!parsed.paths.every((path) => pathHay.includes(path))) {
        return { matched: false, score: 0, kind: 'none' };
      }
    }

    const scoreWords = [...parsed.words];
    if (parsed.phrases.length) {
      // Phrases already required; still boost via word scoring when leftover words exist.
    }
    let match;
    if (!scoreWords.length && (parsed.phrases.length || parsed.paths.length || parsed.extensions.length || parsed.types.length || parsed.has.length || parsed.lacks.length || (parsed.tags && parsed.tags.length) || parsed.person || parsed.modifiedBy || parsed.createdBy)) {
      match = {
        matched: true,
        score: parsed.phrases.length ? 96 : 88,
        kind: parsed.phrases.length ? 'exact' : 'contains',
        source: parsed.tags?.length ? 'Tag' : parsed.paths.length ? 'Path' : 'Filter',
        snippet: parsed.phrases[0] || parsed.tags?.[0] || parsed.paths[0] || '',
      };
    } else if (!scoreWords.length) {
      match = { matched: true, score: 100, kind: 'exact' };
    } else {
      match = state.fuzzy
        ? scoreProject(project, scoreWords, state.wordMode, true, useDeep, state.matchScope)
        : cheapProjectMatch(project, scoreWords, state.wordMode, useDeep, state.matchScope);
    }
    if (!match?.matched) return match;

    if (refineParsed) {
      const refineHay = scopedHaystack(project, state.matchScope, state.deep);
      if (refineParsed.phrases.length && !haystackHasPhrases(refineHay, refineParsed.phrases)) {
        return { matched: false, score: 0, kind: 'none' };
      }
      if (haystackHasExcludes(withTagHay(project._hayDeep || refineHay, project), refineParsed.excludes)) {
        return { matched: false, score: 0, kind: 'none' };
      }
      if (refineParsed.words.length) {
        const refineMatch = state.fuzzy
          ? scoreProject(project, refineParsed.words, state.wordMode, true, useDeep, state.matchScope)
          : cheapProjectMatch(project, refineParsed.words, state.wordMode, useDeep, state.matchScope);
        if (!refineMatch.matched) return refineMatch;
        match = Fuzzy.combineSearchScores(match, refineMatch);
      }
    }
    return attachTagMatchMeta(project, match, parsed, refineParsed);
  };

  const projectPassesQueryFilters = (project, parsed) => {
    const typeNeedles = [...state.types, ...parsed.types];
    if (typeNeedles.length && !typeNeedles.every((type) => projectHasTrait(project, type))) return false;
    if (parsed.extensions.length && !parsed.extensions.every((ext) => projectHasTrait(project, ext))) return false;

    const hasNeedles = [...(state.has ? [state.has] : []), ...parsed.has];
    if (hasNeedles.length && !hasNeedles.every((trait) => projectHasTrait(project, trait))) return false;
    const lackNeedles = [...(state.lacks ? [state.lacks] : []), ...parsed.lacks];
    if (lackNeedles.length && !lackNeedles.every((trait) => !projectHasTrait(project, trait))) return false;

    if (state.who && !personMatchesValue(project, state.who)) return false;
    if (parsed.person && !personMatchesValue(project, parsed.person)) return false;
    if (parsed.modifiedBy) {
      const needle = resolveMeAlias(parsed.modifiedBy);
      if (!String(project.modified_by || '').toLowerCase().includes(needle)) return false;
    }
    if (parsed.createdBy) {
      const needle = resolveMeAlias(parsed.createdBy);
      if (!String(project.person || '').toLowerCase().includes(needle)) return false;
    }
    const tagNeedles = [...(state.tagFilter ? [state.tagFilter] : []), ...(parsed.tags || [])];
    if (tagNeedles.length && !tagNeedles.every((tag) => projectHasTagNeedle(project, tag))) return false;
    if (!projectPassesDate(project)) return false;
    return true;
  };

  const queryIsActive = (parsed = parseActiveQuery()) =>
    !!(
      parsed.words.length ||
      parsed.phrases.length ||
      parsed.excludes.length ||
      parsed.extensions.length ||
      parsed.types.length ||
      parsed.paths.length ||
      parsed.has.length ||
      parsed.lacks.length ||
      (parsed.tags && parsed.tags.length) ||
      parsed.person ||
      parsed.modifiedBy ||
      parsed.createdBy ||
      state.tagFilter ||
      state.refine.trim() ||
      advancedFiltersActive()
    );

  const filteredProjects = () => {
    const parsed = parseActiveQuery();
    const refineParsed = state.refine.trim()
      ? Fuzzy.parseCatalogQuery
        ? Fuzzy.parseCatalogQuery(state.refine)
        : { words: Fuzzy.getSearchWords(state.refine), phrases: [], excludes: [] }
      : null;
    const searching = queryIsActive(parsed) || !!(refineParsed && (refineParsed.words?.length || refineParsed.phrases?.length));
    rememberDefaultSort(searching);
    const presence = presenceMap();

    let results = state.projects.map((project) => ({ project, match: null, deepHits: { hits: [], total: 0 } }));

    if (state.scopeKeys.length) {
      const allowed = new Set(state.scopeKeys);
      results = results.filter((row) => allowed.has(String(row.project?.source_key || '')));
    }

    if (!state.showArchived) {
      results = results.filter((row) => !row.project?.archived);
    }

    if (state.showFavorites) {
      results = results.filter((row) => !!row.project?.favorited);
    }

    results = results.filter((row) => projectPassesQueryFilters(row.project, parsed));
    results = results.filter((row) => projectPassesPresence(row.project, presence));

    if (searching || parsed.words.length || parsed.phrases.length || (refineParsed && refineParsed.words?.length)) {
      results = results
        .map((row) => {
          const match = scoreParsedProject(row.project, parsed, refineParsed);
          return { project: row.project, match, deepHits: { hits: [], total: 0 } };
        })
        .filter((row) => row.match?.matched);
    }

    if (listFiltersActive()) {
      results = results.filter(rowPassesColumnFilters);
    }

    results.sort(compareProjectRows);
    return results;
  };

  const syncAdvancedPanel = () => {
    const open = !!state.advancedOpen;
    if (advancedRoot) {
      advancedRoot.hidden = !open;
      advancedRoot.classList.toggle('is-collapsed', !open);
      advancedRoot.classList.toggle('is-open', open);
    }
    if (advancedToggle) {
      advancedToggle.classList.toggle('is-open', open);
      advancedToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      const arrow = advancedToggle.querySelector('.sp-adv-toggle-arrow');
      if (arrow) arrow.textContent = open ? '▴' : '▾';
      advancedToggle.title = open
        ? 'Hide advanced filters'
        : 'Show date, person, presence, contains/lacks, scope, and save/export options';
    }
  };

  const setAdvancedOpen = (open, persist = true) => {
    state.advancedOpen = !!open;
    syncAdvancedPanel();
    if (!persist) return;
    try {
      localStorage.setItem(STORAGE.advancedOpen, state.advancedOpen ? '1' : '0');
    } catch {
      /* ignore */
    }
  };

  const updateControlsVisibility = () => {
    if (controls) controls.hidden = false;
    syncAdvancedPanel();
    const parsed = parseActiveQuery();
    const refineWords = Fuzzy.getSearchWords(state.refine);
    const multi = parsed.words.length > 1 || refineWords.length > 1;
    if (wordModeGroup) wordModeGroup.hidden = !multi;
    if (matchCluster) matchCluster.hidden = !multi;
    clearBtn?.classList.toggle('is-hidden', state.query.trim() === '');
    refineClear?.classList.toggle('is-hidden', state.refine.trim() === '');
    fuzzyToggle?.classList.toggle('is-active', state.fuzzy);
    fuzzyToggle?.setAttribute('aria-pressed', state.fuzzy ? 'true' : 'false');
    if (deepToggle) {
      deepToggle.classList.toggle('is-active', state.deep);
      deepToggle.setAttribute('aria-pressed', state.deep ? 'true' : 'false');
    }
    if (favoritesToggle) {
      favoritesToggle.classList.toggle('is-active', state.showFavorites);
      favoritesToggle.setAttribute('aria-pressed', state.showFavorites ? 'true' : 'false');
      updateFavoriteToggleText();
    }
    if (archivedToggle) {
      archivedToggle.classList.toggle('is-active', state.showArchived);
      archivedToggle.setAttribute('aria-pressed', state.showArchived ? 'true' : 'false');
    }
    searchRoot.dataset.showArchived = state.showArchived ? '1' : '0';
    document.querySelectorAll('.sharepoint-scope-chip[data-archived="1"]').forEach((chip) => {
      chip.hidden = !state.showArchived;
      chip.classList.toggle('is-archive-revealed', state.showArchived);
    });
    if (suggestToggle) {
      suggestToggle.classList.toggle('is-active', state.suggestEnabled);
      suggestToggle.setAttribute('aria-pressed', state.suggestEnabled ? 'true' : 'false');
    }
    wordModeGroup?.querySelectorAll('[data-word-mode]').forEach((btn) => {
      const active = btn.getAttribute('data-word-mode') === state.wordMode;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    matchScopeGroup?.querySelectorAll('[data-match-scope]').forEach((btn) => {
      const active = btn.getAttribute('data-match-scope') === state.matchScope;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    typeChipsRoot?.querySelectorAll('[data-type-chip]').forEach((btn) => {
      const key = btn.getAttribute('data-type-chip') || '';
      const active = state.types.includes(key);
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    if (datePresetEl && datePresetEl.value !== state.datePreset) datePresetEl.value = state.datePreset || '';
    if (dateFromEl && dateFromEl.value !== state.dateFrom) dateFromEl.value = state.dateFrom || '';
    if (dateToEl && dateToEl.value !== state.dateTo) dateToEl.value = state.dateTo || '';
    const customOpen = state.datePreset === 'custom';
    dateCustomWrap?.classList.toggle('is-hidden', !customOpen);
    if (dateCustomWrap) dateCustomWrap.hidden = !customOpen;
    dateCustomToWrap?.classList.toggle('is-hidden', !customOpen);
    if (dateCustomToWrap) dateCustomToWrap.hidden = !customOpen;
    if (personFilterEl && personFilterEl.value !== state.who) personFilterEl.value = state.who || '';
    if (presenceFilterEl && presenceFilterEl.value !== state.presence) presenceFilterEl.value = state.presence || 'any';
    const showMissing = state.presence === 'missing' && state.scopeKeys.length > 1;
    if (missingWrap) {
      missingWrap.hidden = !showMissing;
      missingWrap.classList.toggle('is-hidden', !showMissing);
    }
    if (missingSourceEl && state.missingSource && missingSourceEl.value !== state.missingSource) {
      missingSourceEl.value = state.missingSource;
    }
    if (hasFilterEl && hasFilterEl.value !== state.has) hasFilterEl.value = state.has || '';
    if (lacksFilterEl && lacksFilterEl.value !== state.lacks) lacksFilterEl.value = state.lacks || '';
    if (presenceWrap) {
      const showPresence = state.scopeKeys.length > 1 || availableSources.length > 1;
      presenceWrap.hidden = !showPresence;
      presenceWrap.classList.toggle('is-hidden', !showPresence);
    }
  };

  /** @type {{ rows: array, query: string, refine: string, deep: boolean, fuzzy: boolean, wordMode: string, matchScope: string, scopeKeys: string[], itemCount: number, ready: boolean, searching: boolean } | null} */
  let liveSearchSnapshot = null;
  /** @type {{ ms: number, seconds: number, secondsLabel: string, msLabel: string, title: string, totalMs: number, totalSecondsLabel: string, totalMsLabel: string } | null} */
  let lastQueryDuration = null;
  let searchCycleStartedAt = 0;

  const nowMs = () =>
    typeof performance !== 'undefined' && typeof performance.now === 'function' ? performance.now() : Date.now();

  const markSearchCycleStart = ({ restart = false } = {}) => {
    if (restart || !searchCycleStartedAt) searchCycleStartedAt = nowMs();
  };

  const formatOneDuration = (ms) => {
    const n = Math.max(0, Number(ms) || 0);
    const seconds = n / 1000;
    let secondsLabel = '< 0.001 s';
    if (n >= 1 && seconds < 1) secondsLabel = `${seconds.toFixed(3)} s`;
    else if (seconds >= 1 && seconds < 10) secondsLabel = `${seconds.toFixed(2)} s`;
    else if (seconds >= 10) secondsLabel = `${seconds.toFixed(1)} s`;

    let msLabel = '< 1 ms';
    if (n >= 1 && n < 10) msLabel = `${n.toFixed(1)} ms`;
    else if (n >= 10) msLabel = `${Math.round(n).toLocaleString()} ms`;

    return { ms: n, seconds, secondsLabel, msLabel };
  };

  const formatSearchDuration = (queryMs, totalMs = null) => {
    const query = formatOneDuration(queryMs);
    const total = formatOneDuration(totalMs == null ? queryMs : totalMs);
    return {
      ...query,
      title: `Query ${query.secondsLabel} (${query.msLabel}). Total ${total.secondsLabel} from start to results (${total.msLabel}).`,
      totalMs: total.ms,
      totalSecondsLabel: total.secondsLabel,
      totalMsLabel: total.msLabel,
    };
  };

  const finishSearchTiming = (searching, queryMs) => {
    if (!searching) {
      lastQueryDuration = null;
      searchCycleStartedAt = 0;
      return;
    }
    const cycleStart = searchCycleStartedAt || nowMs() - queryMs;
    lastQueryDuration = formatSearchDuration(queryMs, Math.max(queryMs, nowMs() - cycleStart));
    searchCycleStartedAt = 0;
  };

  const getLiveSearchSnapshot = () => {
    if (!liveSearchSnapshot) return null;
    return {
      ...liveSearchSnapshot,
      rows: (liveSearchSnapshot.rows || []).map((row) => ({
        project: row.project,
        match: row.match,
        deepHits: row.deepHits || { hits: [], total: 0 },
      })),
    };
  };

  const renderStats = (rows) => {
    const parsed = parseActiveQuery();
    const searching = queryIsActive(parsed);
    if (!searching) {
      liveSearchSnapshot = null;
      lastQueryDuration = null;
      searchCycleStartedAt = 0;
      statsEl.classList.add('is-hidden');
      statsEl.innerHTML = '';
      return;
    }

    const scores = rows.map((row) => row.match?.score || 0).filter((score) => score > 0);
    const exactCount = rows.filter((row) => row.match?.kind === 'exact').length;
    const similarCount = Math.max(0, rows.length - exactCount);
    const avg = scores.length ? Math.round(scores.reduce((a, b) => a + b, 0) / scores.length) : 0;
    const best = scores.length ? Math.max(...scores) : 0;
    const barTone = avg >= 90 ? 'high' : avg >= 75 ? 'mid' : 'low';
    const nestedMatchTotal = rows.reduce((sum, row) => sum + Number(row.deepHits?.total || 0), 0);
    const catalogCount = new Set(rows.map((row) => row.project.source_key).filter(Boolean)).size;
    const tagLabelCounts = new Map();
    let tagMatchCount = 0;
    rows.forEach((row) => {
      const labels = Array.isArray(row.match?.matchedTags)
        ? row.match.matchedTags
        : matchedProjectTagLabels(row.project, collectSearchTagNeedles(parsed));
      if (!labels.length) return;
      tagMatchCount += 1;
      labels.forEach((label) => tagLabelCounts.set(label, (tagLabelCounts.get(label) || 0) + 1));
    });
    const tagLabels = [...tagLabelCounts.keys()];
    const tagTitle = tagLabels
      .map((label) => `${label}${tagLabelCounts.get(label) > 1 ? ` ×${tagLabelCounts.get(label)}` : ''}`)
      .join(', ');
    const tagSummary =
      tagLabels.length === 0
        ? ''
        : tagLabels.length <= 3
          ? ` · ${escapeHtml(tagLabels.join(', '))}`
          : ` · ${escapeHtml(tagLabels.slice(0, 2).join(', '))} +${tagLabels.length - 2}`;

    liveSearchSnapshot = {
      rows,
      query: state.query,
      refine: state.refine,
      deep: !!state.deep,
      fuzzy: !!state.fuzzy,
      wordMode: state.wordMode || 'and',
      matchScope: state.matchScope || 'all',
      scopeKeys: [...(state.scopeKeys || [])],
      itemCount: Number(state.itemCount || 0),
      ready: !!state.ready,
      searching: true,
      exactCount,
      similarCount,
      avg,
      best,
      nestedMatchTotal,
      catalogCount,
      tagMatchCount,
      tagLabels,
      queryMs: lastQueryDuration?.ms ?? null,
      queryTimeLabel: lastQueryDuration?.secondsLabel || '',
      queryTimeMsLabel: lastQueryDuration?.msLabel || '',
      queryTimeTitle: lastQueryDuration?.title || '',
      totalMs: lastQueryDuration?.totalMs ?? lastQueryDuration?.ms ?? null,
      totalTimeLabel: lastQueryDuration?.totalSecondsLabel || lastQueryDuration?.secondsLabel || '',
      totalTimeMsLabel: lastQueryDuration?.totalMsLabel || lastQueryDuration?.msLabel || '',
    };

    statsEl.classList.remove('is-hidden');
    statsEl.innerHTML = `
      <div class="sp-stats-row">
        <span class="sp-stats-title">Search stats</span>
        <button type="button" class="button ghost sp-stats-dash-btn" id="sharepoint-search-dash-open" title="Open search stats dashboard with links to matched files and folders">
          📊 Dashboard
        </button>
        <span>${rows.length} project${rows.length === 1 ? '' : 's'}</span>
        ${nestedMatchTotal ? `<span>${nestedMatchTotal} nested match${nestedMatchTotal === 1 ? '' : 'es'}</span>` : ''}
        ${tagMatchCount ? `<span class="sp-stats-tags" title="${escapeHtml(tagTitle || 'Search tags matched')}">${tagMatchCount} tag match${tagMatchCount === 1 ? '' : 'es'}${tagSummary}</span>` : ''}
        ${state.scopeKeys.length > 1 ? `<span>${catalogCount} catalog${catalogCount === 1 ? '' : 's'} hit</span>` : ''}
        <span class="sp-stats-exact">${exactCount} exact</span>
        <span class="sp-stats-similar">${similarCount} similar</span>
        <span class="sp-stats-avg">Avg ${avg}%</span>
        <span>Best ${best}%</span>
        ${state.fuzzy ? '<span class="sp-stats-fuzzy">Fuzzy on</span>' : ''}
        ${state.matchScope !== 'all' ? `<span class="sp-stats-scope">Scope: ${escapeHtml(state.matchScope)}</span>` : ''}
        ${state.deep || state.matchScope === 'files' ? '<span class="sp-stats-deep">Deep files on</span>' : '<span class="sp-stats-deep">Folder names only</span>'}
        ${state.refine.trim() ? `<span class="sp-stats-refine">Refined with “${escapeHtml(state.refine.trim())}”</span>` : ''}
      </div>
      <div class="sp-stats-bar-track">
        <div class="sp-stats-bar-rail">
          <div class="sp-stats-bar-fill sp-stats-bar-fill--${barTone}" style="width:${Math.max(avg, 4)}%"></div>
        </div>
        <span class="sp-stats-bar-label">${avg}% match probability</span>
      </div>`;
  };

  const getIndexedCatalogProject = (sourceKey, projectName) => {
    const key = String(sourceKey || '');
    const name = String(projectName || '')
      .trim()
      .toLowerCase();
    if (!key || !name) return null;
    const pools = [];
    if (Array.isArray(state.indexBySource?.[key])) pools.push(state.indexBySource[key]);
    if (Array.isArray(state.projects)) pools.push(state.projects);
    for (const rows of pools) {
      const found = rows.find((project) => {
        if (String(project?.source_key || '') !== key) return false;
        return (
          String(project?.project_name || '')
            .trim()
            .toLowerCase() === name
        );
      });
      if (found) return found;
    }
    return null;
  };

  const catalogIndexHasSources = (keys) =>
    (Array.isArray(keys) ? keys : [keys]).every((key) => {
      const sourceKey = String(key || '');
      if (!sourceKey) return false;
      if (Object.prototype.hasOwnProperty.call(state.indexBySource || {}, sourceKey)) return true;
      return (state.projects || []).some((project) => String(project?.source_key || '') === sourceKey);
    });

  const catalogProjectMatches = (project, spec = {}) => {
    if (!project) return false;
    const parsed = Fuzzy.parseCatalogQuery
      ? Fuzzy.parseCatalogQuery(spec.query || '')
      : {
          words: Fuzzy.getSearchWords?.(spec.query || '') || [],
          phrases: [],
          excludes: [],
          extensions: [],
          types: [],
          paths: [],
          has: [],
          lacks: [],
          tags: [],
          person: '',
          modifiedBy: '',
          createdBy: '',
        };
    const types = Array.isArray(spec.types) ? spec.types : [];
    const extensions = Array.isArray(spec.extensions) ? spec.extensions : [];
    const matchScope = spec.matchScope || 'all';
    const deep = spec.deep !== false;
    const wordMode = spec.wordMode === 'or' ? 'or' : 'and';
    const fuzzy = !!spec.fuzzy;
    const typeNeedles = [...types, ...(parsed.types || [])];
    if (typeNeedles.some((trait) => !projectHasTrait(project, trait))) return false;
    const extNeedles = [...extensions, ...(parsed.extensions || [])];
    if (extNeedles.some((trait) => !projectHasTrait(project, trait))) return false;
    if ((parsed.has || []).some((trait) => !projectHasTrait(project, trait))) return false;
    if ((parsed.lacks || []).some((trait) => projectHasTrait(project, trait))) return false;
    if (parsed.person && !personMatchesValue(project, parsed.person)) return false;
    if (parsed.modifiedBy) {
      const needle = resolveMeAlias(parsed.modifiedBy);
      if (!String(project.modified_by || '')
        .toLowerCase()
        .includes(needle)) {
        return false;
      }
    }
    if (parsed.createdBy) {
      const needle = resolveMeAlias(parsed.createdBy);
      if (!String(project.person || '')
        .toLowerCase()
        .includes(needle)) {
        return false;
      }
    }
    if ((parsed.tags || []).some((tag) => !projectHasTagNeedle(project, tag))) return false;
    const hay = scopedHaystack(project, matchScope, deep);
    const fullHay = withTagHay(project._hayDeep || hay, project);
    if (parsed.phrases?.length && !haystackHasPhrases(hay, parsed.phrases)) return false;
    if (haystackHasExcludes(fullHay, parsed.excludes || [])) return false;
    if (parsed.paths?.length) {
      const pathHay = project._hayFiles || fullHay;
      if (!parsed.paths.every((path) => pathHay.includes(path))) return false;
    }
    if (!(parsed.words || []).length) return true;
    const useDeep = effectiveDeep(matchScope, deep);
    const match = fuzzy
      ? scoreProject(project, parsed.words, wordMode, true, useDeep, matchScope)
      : cheapProjectMatch(project, parsed.words, wordMode, useDeep, matchScope);
    return !!match?.matched;
  };

  window.RiskRegisterSharePoint = Object.assign(window.RiskRegisterSharePoint || {}, {
    getLiveSearchSnapshot,
    collectDeepHits,
    formatAgeLabel,
    scoreBadgeHtml,
    getIndexedCatalogProject,
    catalogIndexHasSources,
    catalogProjectMatches,
  });

  const renderMeta = (matchedCount) => {
    const searching = queryIsActive();
    let html = `${matchedCount} project${matchedCount === 1 ? '' : 's'}${searching ? ' matched' : ''}`;
    html += ` · ${state.itemCount} catalog item${state.itemCount === 1 ? '' : 's'} total`;
    const archivedCount = state.projects.filter((project) => project.archived).length;
    if (archivedCount && catalogCanArchive()) {
      html += state.showArchived
        ? ` · showing ${archivedCount} archived`
        : ` · ${archivedCount} archived hidden`;
    }
    if (state.scopeKeys.length > 1) {
      html += ` · Searching ${state.scopeKeys.length} catalogs`;
    }
    if (state.lastSynced && state.scopeKeys.length <= 1) {
      html += ` · Last update ${escapeHtml(state.lastSynced)}`;
      if (state.lastStatus) html += ` (${escapeHtml(state.lastStatus)})`;
    }
    if (state.ready) html += ' · <span class="sp-live-pill">⚡ Live search</span>';
    if (searching && lastQueryDuration) {
      const queryLabel = lastQueryDuration.secondsLabel;
      const totalLabel = lastQueryDuration.totalSecondsLabel || queryLabel;
      html += ` · <span class="sp-live-pill sp-live-pill--time" title="${escapeHtml(lastQueryDuration.title)}">⏱ ${escapeHtml(
        queryLabel
      )} · ${escapeHtml(totalLabel)} total</span>`;
    }
    if (searching && (state.deep || state.matchScope === 'files')) html += ' · <span class="sp-live-pill sp-live-pill--deep">📂 Deep files</span>';
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
        scheduleUrlSync();
        document.getElementById('sharepoint-table-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
  };

  const snapshotSearchState = () => ({
    query: state.query,
    refine: state.refine,
    wordMode: state.wordMode,
    fuzzy: state.fuzzy,
    deep: state.deep,
    matchScope: state.matchScope,
    types: [...state.types],
    datePreset: state.datePreset,
    dateFrom: state.dateFrom,
    dateTo: state.dateTo,
    who: state.who,
    presence: state.presence,
    missingSource: state.missingSource,
    has: state.has,
    lacks: state.lacks,
    scopeKeys: [...state.scopeKeys],
  });

  const readSavedSearches = () => {
    try {
      const raw = JSON.parse(localStorage.getItem(STORAGE.saved) || '[]');
      if (!Array.isArray(raw)) return [];
      return raw
        .filter((item) => item && typeof item === 'object' && String(item.name || '').trim())
        .slice(0, SAVED_MAX);
    } catch {
      return [];
    }
  };

  const writeSavedSearches = (items) => {
    try {
      localStorage.setItem(STORAGE.saved, JSON.stringify(items.slice(0, SAVED_MAX)));
    } catch {
      /* ignore */
    }
  };

  let savedPaintedKey = '';

  const renderSavedSearches = () => {
    const items = readSavedSearches();
    if (savedRoot && savedChips) {
      const paintKey = items.map((item) => `${item.id}:${item.name}:${item.kind || ''}`).join('\u0001');
      if (paintKey !== savedPaintedKey) {
        savedPaintedKey = paintKey;
        if (!items.length) {
          savedRoot.hidden = true;
          savedRoot.classList.add('is-hidden');
          savedChips.innerHTML = '';
        } else {
          savedRoot.hidden = false;
          savedRoot.classList.remove('is-hidden');
          savedChips.innerHTML = items
            .map((item) => {
              const isDash = item.kind === 'dashboard';
              const label = `${isDash ? '📊 ' : ''}${item.name}`;
              const title = isDash
                ? `Dashboard snapshot: ${item.query || item.name}`
                : item.query || item.name;
              return `<span class="sharepoint-recent-chip-wrap${isDash ? ' is-dashboard-snap' : ''}" role="listitem">
          <button type="button" class="sharepoint-recent-chip${isDash ? ' sp-saved-dash-chip' : ''}" data-saved-id="${escapeHtml(String(item.id))}" title="${escapeHtml(title)}">${escapeHtml(label)}</button>
          <button type="button" class="sharepoint-recent-remove" data-saved-remove="${escapeHtml(String(item.id))}" title="Remove saved search" aria-label="Remove saved search">×</button>
        </span>`;
            })
            .join('');
        }
      }
    }
    paintDialogSavedPresets();
  };

  const applySavedSearch = (id) => {
    const item = readSavedSearches().find((entry) => String(entry.id) === String(id));
    if (!item || !item.state) return;
    const snap = item.state;
    state.query = String(snap.query || '');
    state.refine = String(snap.refine || '');
    state.wordMode = snap.wordMode === 'or' ? 'or' : 'and';
    state.fuzzy = !!snap.fuzzy;
    state.deep = snap.deep !== false;
    state.matchScope = MATCH_SCOPES.has(snap.matchScope) ? snap.matchScope : 'all';
    state.types = Array.isArray(snap.types) ? snap.types.filter((t) => TYPE_CHIP_KEYS.has(t)) : [];
    state.datePreset = DATE_PRESETS.has(snap.datePreset) ? snap.datePreset : '';
    state.dateFrom = String(snap.dateFrom || '');
    state.dateTo = String(snap.dateTo || '');
    state.who = String(snap.who || '');
    state.presence = PRESENCE_MODES.has(snap.presence) ? snap.presence : 'any';
    state.missingSource = String(snap.missingSource || '');
    state.has = HAS_LACK_KEYS.has(snap.has) ? snap.has : '';
    state.lacks = HAS_LACK_KEYS.has(snap.lacks) ? snap.lacks : '';
    localStorage.setItem(STORAGE.fuzzy, state.fuzzy ? '1' : '0');
    localStorage.setItem(STORAGE.deep, state.deep ? '1' : '0');
    localStorage.setItem(STORAGE.wordMode, state.wordMode);
    hideSuggestions();
    if (
      snap.matchScope !== 'all' ||
      snap.datePreset ||
      snap.who ||
      (snap.presence && snap.presence !== 'any') ||
      snap.has ||
      snap.lacks
    ) {
      setAdvancedOpen(true);
    }
    applySearch({ resetPage: true, syncInputs: true });
    if (item.kind === 'dashboard') {
      window.setTimeout(() => {
        window.RiskRegisterSharePoint?.openSearchDashboard?.(item.dashboardUi || null);
      }, 250);
    }
  };

  const saveCurrentSearch = (options = {}) => {
    const isDash = options.kind === 'dashboard';
    const defaultName = isDash
      ? `📊 ${state.query.trim() || 'Dashboard'}`
      : state.query.trim() || 'Saved search';
    const label =
      options.name != null
        ? String(options.name)
        : window.prompt(isDash ? 'Name this dashboard snapshot' : 'Name this saved search', defaultName);
    if (label == null) return null;
    const name = String(label).trim();
    if (!name) return null;
    const items = readSavedSearches().filter((item) => item.name.toLowerCase() !== name.toLowerCase());
    const entry = {
      id: `${Date.now()}`,
      name,
      query: state.query.trim(),
      state: snapshotSearchState(),
      kind: isDash ? 'dashboard' : 'search',
    };
    if (isDash && options.dashboardUi) entry.dashboardUi = options.dashboardUi;
    items.unshift(entry);
    writeSavedSearches(items);
    savedPaintedKey = '';
    renderSavedSearches();
    return entry;
  };

  const removeSavedSearch = (id) => {
    writeSavedSearches(readSavedSearches().filter((item) => String(item.id) !== String(id)));
    savedPaintedKey = '';
    renderSavedSearches();
  };

  window.RiskRegisterSharePoint = Object.assign(window.RiskRegisterSharePoint || {}, {
    saveNamedSearch: saveCurrentSearch,
    refreshSavedSearches: () => {
      savedPaintedKey = '';
      renderSavedSearches();
    },
    getCatalogDialogQuery: () =>
      [state.query, state.refine]
        .map((part) => String(part || '').trim())
        .filter(Boolean)
        .join(' '),
  });

  const populatePersonFilter = () => {
    if (!personFilterEl) return;
    const current = state.who || personFilterEl.value || '';
    const people = new Map();
    state.projects.forEach((project) => {
      [project.modified_by, project.person].forEach((name) => {
        const display = String(name || '').trim();
        if (!display) return;
        const key = display.toLowerCase();
        if (!people.has(key)) people.set(key, display);
      });
    });
    const sorted = [...people.values()].sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }));
    const hasMe = !!(currentUser.display || currentUser.name);
    personFilterEl.innerHTML =
      `<option value="">Anyone</option>` +
      (hasMe ? `<option value="me">Me (${escapeHtml(searchRoot.getAttribute('data-user-display') || searchRoot.getAttribute('data-user-name') || 'me')})</option>` : '') +
      sorted.map((name) => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('');
    if (current && [...personFilterEl.options].some((opt) => opt.value === current)) {
      personFilterEl.value = current;
    } else {
      personFilterEl.value = '';
      state.who = '';
    }
  };

  const hideSuggestions = () => {
    state.suggestOpen = false;
    state.suggestIndex = -1;
    state.suggestItems = [];
    if (suggestEl) {
      suggestEl.hidden = true;
      suggestEl.classList.add('is-hidden');
      suggestEl.innerHTML = '';
    }
    input?.setAttribute('aria-expanded', 'false');
  };

  const buildSuggestions = (rawQuery) => {
    const q = String(rawQuery || '').trim().toLowerCase();
    if (q.length < 1) return [];
    const items = [];
    const push = (group, label, value, action, meta = '') => {
      if (items.length >= 24) return;
      items.push({ group, label, value, action, meta });
    };

    const projects = state.projects
      .filter((project) => String(project.project_name || '').toLowerCase().includes(q))
      .slice(0, 8);
    projects.forEach((project) => {
      push(
        'Projects',
        String(project.project_name || ''),
        String(project.project_name || ''),
        'open-project',
        String(project.source_title || '')
      );
    });

    const fileHits = [];
    state.projects.forEach((project) => {
      projectEntries(project).forEach((entry) => {
        if (fileHits.length >= 8) return;
        const name = String(entry.name || '');
        if (!name.toLowerCase().includes(q)) return;
        fileHits.push({
          name,
          projectName: String(project.project_name || ''),
          sourceKey: String(project.source_key || ''),
        });
      });
    });
    fileHits.slice(0, 8).forEach((hit) => {
      push('Files', hit.name, hit.name, 'set-query', hit.projectName);
    });

    const people = new Set();
    state.projects.forEach((project) => {
      [project.modified_by, project.person].forEach((name) => {
        const display = String(name || '').trim();
        if (display && display.toLowerCase().includes(q)) people.add(display);
      });
    });
    [...people].slice(0, 8).forEach((name) => {
      push('People', name, `person:${name.includes(' ') ? `"${name}"` : name}`, 'insert-operator');
    });

    const operators = [
      { label: 'tag:priority', value: 'tag:priority' },
      { label: 'ext:pdf', value: 'ext:pdf' },
      { label: 'ext:vsdx', value: 'ext:vsdx' },
      { label: 'type:visio', value: 'type:visio' },
      { label: 'type:cad', value: 'type:cad' },
      { label: 'type:drawings', value: 'type:drawings' },
      { label: 'person:me', value: 'person:me' },
      { label: 'has:pdf', value: 'has:pdf' },
      { label: 'has:visio', value: 'has:visio' },
      { label: 'lacks:pdf', value: 'lacks:pdf' },
      { label: 'path:drawings', value: 'path:drawings' },
    ].filter((op) => op.label.includes(q) || q.endsWith(':') || q.length <= 2);
    operators.slice(0, 8).forEach((op) => push('Operators', op.label, op.value, 'insert-operator'));

    (state.allTags || []).forEach((tag) => {
      const label = String(tag.label || '').trim();
      if (!label) return;
      const lower = label.toLowerCase();
      if (q && !lower.includes(q) && !String(tag.slug || '').includes(q) && !q.startsWith('tag')) return;
      const wantsOperator = q.startsWith('tag');
      const quoted = label.includes(' ');
      const value = wantsOperator
        ? quoted
          ? `tag:"${label}"`
          : `tag:${label}`
        : label;
      push('Tags', label, value, wantsOperator ? 'insert-operator' : 'set-query');
    });

    return items;
  };

  const renderSuggestions = () => {
    if (!suggestEl || !input) return;
    if (!state.suggestEnabled) {
      hideSuggestions();
      return;
    }
    const items = buildSuggestions(input.value);
    state.suggestItems = items;
    state.suggestIndex = items.length ? 0 : -1;
    if (!items.length) {
      hideSuggestions();
      return;
    }
    let html = '';
    let lastGroup = '';
    items.forEach((item, index) => {
      if (item.group !== lastGroup) {
        lastGroup = item.group;
        html += `<div class="sp-suggest-group" role="presentation">${escapeHtml(item.group)}</div>`;
      }
      html += `<button type="button" class="sp-suggest-item${index === state.suggestIndex ? ' is-active' : ''}" role="option" data-suggest-index="${index}" aria-selected="${index === state.suggestIndex ? 'true' : 'false'}">
        <span class="sp-suggest-label">${escapeHtml(item.label)}</span>
        ${item.meta ? `<span class="sp-suggest-meta">${escapeHtml(item.meta)}</span>` : ''}
      </button>`;
    });
    suggestEl.innerHTML = html;
    suggestEl.hidden = false;
    suggestEl.classList.remove('is-hidden');
    state.suggestOpen = true;
    input.setAttribute('aria-expanded', 'true');
  };

  const scheduleSuggestions = debouncePaint(() => {
    if (!state.suggestEnabled) {
      hideSuggestions();
      return;
    }
    if (document.activeElement === input) renderSuggestions();
  }, 90);

  const applySuggestion = (item) => {
    if (!item) return;
    hideSuggestions();
    if (item.action === 'open-project') {
      const project = state.projects.find(
        (row) => String(row.project_name || '').toLowerCase() === String(item.value || '').toLowerCase()
      );
      if (project && typeof openProject === 'function') {
        openProject(String(project.project_name || ''), String(project.source_key || state.sourceKey || ''));
        return;
      }
      state.query = item.value;
      applySearch({ resetPage: true, syncInputs: true });
      return;
    }
    if (item.action === 'set-query') {
      state.query = item.value;
      applySearch({ resetPage: true, syncInputs: true });
      return;
    }
    if (item.action === 'insert-operator') {
      const current = String(input?.value || '').trim();
      const next = current ? `${current} ${item.value}` : item.value;
      state.query = next;
      applySearch({ resetPage: true, syncInputs: true });
      input?.focus();
    }
  };

  const csvEscape = (value) => {
    const text = String(value ?? '');
    if (/[",\n\r]/.test(text)) return `"${text.replace(/"/g, '""')}"`;
    return text;
  };

  const exportFilteredCsv = () => {
    const rows = filteredProjects();
    const header = [
      'project',
      'catalog',
      'files',
      'folders',
      'modified',
      'modified_by',
      'created_by',
      'match_score',
      'sharepoint_url',
    ];
    const lines = [header.join(',')];
    rows.forEach(({ project, match }) => {
      lines.push(
        [
          project.project_name,
          project.source_title,
          project.file_count,
          project.folder_count,
          project.last_modified,
          project.modified_by,
          project.person,
          match?.score ?? '',
          project.folder_url,
        ]
          .map(csvEscape)
          .join(',')
      );
    });
    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `catalog-search-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  };

  const isTypingTarget = (el) => {
    if (!el) return false;
    const tag = String(el.tagName || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable;
  };

  let resultSettleTimer = 0;
  const settleProjectResults = () => {
    if (!resultCard || prefersReducedMotion()) return;

    window.clearTimeout(resultSettleTimer);
    resultCard.classList.remove('is-results-settling');
    const rows = [...tbody.querySelectorAll('.sharepoint-project-row')];
    rows.forEach((row) => {
      row.classList.remove('is-results-settling');
      row.style.removeProperty('--sp-result-delay');
    });
    if ((resultCard.dataset.listAnimation || 'soft-landing') === 'none') return;

    // Restart the short reveal when a new result set replaces the current one.
    void resultCard.offsetWidth;
    resultCard.classList.add('is-results-settling');
    rows.slice(0, 24).forEach((row, index) => {
      row.classList.add('is-results-settling');
      row.style.setProperty('--sp-result-delay', `${Math.min(index * 22, 220)}ms`);
    });

    const durationMs =
      Number.parseFloat(getComputedStyle(resultCard).getPropertyValue('--sp-user-animation-duration')) || 580;
    resultSettleTimer = window.setTimeout(() => {
      resultCard.classList.remove('is-results-settling');
      rows.forEach((row) => {
        row.classList.remove('is-results-settling');
        row.style.removeProperty('--sp-result-delay');
      });
    }, durationMs + 360);
  };
  window.RiskRegisterSharePoint = Object.assign(window.RiskRegisterSharePoint || {}, {
    replayListAnimation: settleProjectResults,
  });

  const render = () => {
    updateControlsVisibility();
    updateHeading();
    syncScopeChips();
    if (!searchCycleStartedAt) searchCycleStartedAt = nowMs();
    const queryStarted = nowMs();
    const rows = filteredProjects();
    const total = rows.length;
    const from = total === 0 ? 0 : (state.page - 1) * state.perPage + 1;
    const to = Math.min(total, state.page * state.perPage);
    const pageRows = rows.slice((state.page - 1) * state.perPage, state.page * state.perPage);
    const parsed = parseActiveQuery();
    const searching = queryIsActive(parsed);
    const presence = presenceMap();
    const useDeep = effectiveDeep(state.matchScope, state.deep);
    if (searching) {
      const words = [...parsed.words, ...parsed.phrases];
      if (useDeep && words.length) {
        pageRows.forEach((row) => {
          row.deepHits = collectDeepHits(row.project, words, state.wordMode, state.fuzzy);
        });
        rows.forEach((row) => {
          if (row.deepHits?.total) return;
          row.deepHits = {
            hits: [],
            total: collectDeepHits(row.project, words, state.wordMode, state.fuzzy, 0).total,
          };
        });
      }
    }
    const queryMs = nowMs() - queryStarted;

    resultCountEl.textContent = `Showing ${from}–${to} of ${total}${listFiltersActive() || advancedFiltersActive() || state.showFavorites ? ' · filtered' : ''}`;
    renderPagination(total);
    syncListSortHeaders();
    syncListFilterUi();
    renderRecentSearches();
    renderSavedSearches();

    if (pageRows.length === 0) {
      tbody.innerHTML = `<tr class="sharepoint-empty-row"><td colspan="${listVisibleColspan()}">${
        state.loadingIndex
          ? '⏳ Loading live search index…'
          : state.projects.length === 0
            ? '📁 No catalog items yet.'
            : !state.showArchived && state.projects.every((project) => project.archived)
              ? '📦 All matching projects are archived. Turn on <strong>Show archived</strong> to restore them.'
            : state.showFavorites && !state.projects.some((project) => project.favorited)
              ? '★ No favorite projects yet. Star a project to pin it here.'
            : state.showFavorites && !rows.length
              ? '★ No favorites match the current search or filters.'
            : listFiltersActive() || advancedFiltersActive()
              ? 'No projects match these filters. Clear filters or try another search.'
              : searching
              ? `No projects matched <strong>${escapeHtml(state.query.trim() || 'filters')}</strong> in the selected catalog${state.scopeKeys.length === 1 ? '' : 's'}. ${useDeep ? 'Try Fuzzy, OR mode, or another file or folder name.' : 'Turn on Deep files to search nested files, or try Fuzzy / OR mode.'}`
              : 'No projects to show.'
      }</td></tr>`;
      syncCompareBar();
      finishSearchTiming(searching, queryMs);
      renderMeta(total);
      renderStats(rows);
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
        const hitSet = searching && useDeep ? deepHits : null;
        const openQuery = '';
        const extra = `${hitSet ? deepHitsHtml(hitSet) : ''}${coverageHtml(project, presence)}`;
        return `<tr class="sharepoint-project-row${isSelected ? ' is-compare-selected' : ''}${hitSet?.total ? ' has-deep-hits' : ''}${project.archived ? ' is-archived' : ''}${project.favorited ? ' is-favorite' : ''}" data-project-name="${escapeHtml(name)}" data-source-key="${escapeHtml(sourceKey)}" data-open-query="${escapeHtml(openQuery)}" tabindex="0">
          <td class="sharepoint-select-col" data-col="select" onclick="event.stopPropagation()">
            <label class="sharepoint-row-select">
              <input type="checkbox" class="sharepoint-compare-check" value="${escapeHtml(selectId)}" data-project-name="${escapeHtml(name)}" data-source-key="${escapeHtml(sourceKey)}" ${isSelected ? 'checked' : ''} aria-label="Select ${escapeHtml(name)} for compare">
            </label>
          </td>
          <td data-col="name">
            ${projectNameCellHtml(project, sourceKey, sourceTitle, extra, openQuery)}
          </td>
          <td class="sp-match-cell" data-col="match">${searching ? scoreBadgeHtml(match, project) : scoreBadgeHtml(null, project)}</td>
          <td class="sp-meta-cell" data-col="items">${itemCountsHtml(project)}</td>
          <td class="sp-meta-cell" data-col="modified">${escapeHtml(formatModified(project.last_modified))}</td>
          <td class="sp-meta-cell" data-col="modified_by">${personCellHtml(modifiedBy, '👤')}</td>
          <td class="sp-meta-cell" data-col="created_by">${personCellHtml(person, '🙋')}</td>
          <td class="sharepoint-project-actions" data-col="actions">
            ${
              folderUrl || catalogCanArchive() || catalogCanFavorite()
                ? `<div class="sharepoint-project-action-group">
                    ${favoriteToggleHtml({
                      favorited: !!project.favorited,
                      title: project.favorited ? 'Remove from favorites' : 'Add to favorites',
                      extraClass: 'sp-favorite-project-btn',
                      attrs: {
                        'data-scope': 'project',
                        'data-source-key': sourceKey,
                        'data-project-name': name,
                      },
                    })}
                    ${
                      folderUrl
                        ? `<a class="button ghost-light sharepoint-open-sp" href="${escapeHtml(folderUrl)}" target="_blank" rel="noopener noreferrer" title="Open in SharePoint" onclick="event.stopPropagation()">🔗</a>
                    <button type="button" class="button ghost-light sp-copy-link-btn sp-project-copy-btn" data-copy-url="${escapeHtml(folderUrl)}" data-label="📋" title="Copy SharePoint link" aria-label="Copy link for ${escapeHtml(name)}" onclick="event.stopPropagation()">📋</button>
                    ${qrButtonHtml(folderUrl, name, {
                      sourceKey,
                      catalog: sourceTitle,
                    })}`
                        : ''
                    }
                    ${archiveToggleHtml({
                      archived: !!project.archived,
                      disabled: project.archive_scope === 'source',
                      title:
                        project.archive_scope === 'source'
                          ? 'This catalog folder is archived. Unarchive the folder card first.'
                          : project.archived
                            ? 'Show this project on the dashboard again'
                            : 'Hide this project from the dashboard',
                      extraClass: 'sp-archive-project-btn',
                      attrs: {
                        'data-source-key': sourceKey,
                        'data-project-name': name,
                      },
                    })}
                  </div>`
                : ''
            }
          </td>
        </tr>`;
      })
      .join('');

    settleProjectResults();
    applyListColumnOrder();
    bindRowEvents();
    bindQrButtons(tbody);
    syncCompareBar();
    finishSearchTiming(searching, queryMs);
    renderMeta(total);
    renderStats(rows);
  };

  const readRecentSearches = () => {
    try {
      const raw = JSON.parse(localStorage.getItem(STORAGE.recent) || '[]');
      if (!Array.isArray(raw)) return [];
      return raw
        .map((item) => String(item || '').trim())
        .filter((item) => item.length >= RECENT_MIN_LEN)
        .slice(0, RECENT_MAX);
    } catch {
      return [];
    }
  };

  const writeRecentSearches = (items) => {
    try {
      localStorage.setItem(STORAGE.recent, JSON.stringify(items.slice(0, RECENT_MAX)));
    } catch {
      /* ignore quota / private mode */
    }
  };

  let recentPaintedKey = '';

  const renderRecentSearches = () => {
    if (!recentRoot || !recentChips) return;
    const items = readRecentSearches();
    const active = state.query.trim();
    const paintKey = `${items.join('\u0001')}::${active.toLowerCase()}`;
    if (paintKey === recentPaintedKey) return;
    recentPaintedKey = paintKey;

    if (!items.length) {
      recentRoot.hidden = true;
      recentRoot.classList.add('is-hidden');
      recentChips.innerHTML = '';
      if (recentClearBtn) recentClearBtn.hidden = true;
      return;
    }

    recentRoot.hidden = false;
    recentRoot.classList.remove('is-hidden');
    if (recentClearBtn) recentClearBtn.hidden = false;
    const activeLower = active.toLowerCase();
    recentChips.innerHTML = items
      .map((query) => {
        const isActive = query.toLowerCase() === activeLower;
        return `<span class="sharepoint-recent-chip-wrap${isActive ? ' is-active' : ''}" role="listitem">
          <button type="button" class="sharepoint-recent-chip" data-recent-query="${escapeHtml(query)}" title="${escapeHtml(query)}" aria-pressed="${isActive ? 'true' : 'false'}">${escapeHtml(query)}</button>
          <button type="button" class="sharepoint-recent-remove" data-recent-remove="${escapeHtml(query)}" title="Remove “${escapeHtml(query)}”" aria-label="Remove recent search ${escapeHtml(query)}">×</button>
        </span>`;
      })
      .join('');
  };

  const rememberRecentSearch = (query) => {
    const nextQuery = String(query || '').trim();
    if (nextQuery.length < RECENT_MIN_LEN) return;
    const items = readRecentSearches();
    const next = [nextQuery, ...items.filter((item) => item.toLowerCase() !== nextQuery.toLowerCase())].slice(
      0,
      RECENT_MAX
    );
    writeRecentSearches(next);
    recentPaintedKey = '';
    renderRecentSearches();
  };

  const scheduleRememberRecent = debouncePaint(() => rememberRecentSearch(state.query), 900);

  const applyRecentSearch = (query) => {
    state.query = String(query || '').trim();
    state.refine = '';
    scheduleTypedSearch.cancel();
    scheduleRememberRecent.cancel();
    rememberRecentSearch(state.query);
    applySearch({ resetPage: true, syncInputs: true });
    input?.focus();
  };

  const removeRecentSearch = (query) => {
    const needle = String(query || '').trim().toLowerCase();
    writeRecentSearches(readRecentSearches().filter((item) => item.toLowerCase() !== needle));
    recentPaintedKey = '';
    renderRecentSearches();
  };

  const syncUrl = () => {
    const params = new URLSearchParams();
    const catalogSolo = searchRoot.getAttribute('data-solo') === '1';
    if (publicShare) {
      const token = (searchRoot.getAttribute('data-share-token') || '').trim();
      if (token) params.set('t', token);
    }
    if (catalogSolo) params.set('view', 'catalog');
    if (state.sourceKey) params.set('source', state.sourceKey);
    if (state.scopeKeys.length > 1) {
      params.set('sources', state.scopeKeys.join(','));
    }
    if (state.query.trim()) params.set('q', state.query.trim());
    if (state.refine.trim()) params.set('refine', state.refine.trim());
    if (state.matchScope && state.matchScope !== 'all') params.set('scope', state.matchScope);
    if (state.types.length) params.set('type', state.types.join(','));
    if (state.datePreset) params.set('date', state.datePreset);
    if (state.datePreset === 'custom' && state.dateFrom) params.set('from', state.dateFrom);
    if (state.datePreset === 'custom' && state.dateTo) params.set('to', state.dateTo);
    if (state.who) params.set('who', state.who);
    if (state.presence && state.presence !== 'any') params.set('presence', state.presence);
    if (state.presence === 'missing' && state.missingSource) params.set('missing', state.missingSource);
    if (state.has) params.set('has', state.has);
    if (state.lacks) params.set('lacks', state.lacks);
    if (state.fuzzy) params.set('fuzzy', '1');
    if (!state.deep) params.set('deep', '0');
    if (state.showArchived) params.set('archived', '1');
    if (state.wordMode === 'or') params.set('mode', 'or');
    if (state.perPage !== 25) params.set('per', String(state.perPage));
    if (state.page > 1) params.set('page', String(state.page));
    const qs = params.toString();
    const shareActionReturn = /[?&](catalog_shared|owners_shared|emailed)=/.test(window.location.search);
    let hash = '';
    if (!catalogSolo) {
      if (window.location.hash === '#sharepoint-owner-dash') {
        hash = '#sharepoint-owner-dash';
      } else if (
        window.location.hash === '#catalog-share-panel'
        || window.location.hash === '#owners-share-panel'
      ) {
        hash = window.location.hash;
      } else if (shareActionReturn) {
        // Keep viewport stable after create/revoke/purge/email — do not force #sharepoint-search.
        hash = '';
      } else if (
        window.location.hash === '#sharepoint-search'
        || window.location.hash === '#sharepoint-table-card'
      ) {
        hash = window.location.hash;
      } else if (window.location.hash) {
        hash = window.location.hash;
      } else {
        hash = '#sharepoint-search';
      }
    }
    const next = `${window.location.pathname}${qs ? `?${qs}` : ''}${hash}`;
    window.history.replaceState(null, '', next);
  };

  const applySearch = ({ resetPage = true, syncInputs = false } = {}) => {
    markSearchCycleStart();
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
    ensurePeerIndexes();
    if (state.query.trim().length >= RECENT_MIN_LEN) {
      scheduleRememberRecent();
    } else {
      scheduleRememberRecent.cancel();
    }
  };

  let typedSearchTimer = 0;
  let typedSearchRaf = 0;
  const cancelTypedSearch = () => {
    window.clearTimeout(typedSearchTimer);
    typedSearchTimer = 0;
    if (typedSearchRaf) {
      window.cancelAnimationFrame(typedSearchRaf);
      typedSearchRaf = 0;
    }
  };
  const scheduleTypedSearch = () => {
    cancelTypedSearch();
    markSearchCycleStart({ restart: true });
    typedSearchRaf = window.requestAnimationFrame(() => {
      typedSearchRaf = 0;
      const heavy = state.fuzzy && (state.deep || state.matchScope === 'files');
      typedSearchTimer = window.setTimeout(() => {
        typedSearchTimer = 0;
        runTypedSearch();
      }, heavy ? 180 : 70);
    });
  };
  scheduleTypedSearch.cancel = cancelTypedSearch;
  scheduleTypedSearch.flush = () => {
    cancelTypedSearch();
    runTypedSearch();
  };
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
    if (peerIndexAbort) {
      try {
        peerIndexAbort.abort();
      } catch {
        /* ignore */
      }
      peerIndexAbort = null;
      peerIndexRequestKey = '';
    }
    state.loadingIndex = true;
    state.ready = false;
    tbody.innerHTML = `<tr class="sharepoint-empty-row"><td colspan="${listVisibleColspan()}">⏳ Loading live search index…</td></tr>`;
    syncActiveCatalogChrome();

    const extra = {
      source: keys[0],
      sources: keys.join(','),
    };

    fetch(catalogApiUrl('search_index', extra), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json', 'Cache-Control': 'no-cache' },
    })
      .then((response) => response.json())
      .then((payload) => {
        if (!payload?.ok || !Array.isArray(payload.projects)) {
          throw new Error(payload?.error || 'Unable to load search index.');
        }
        state.projects = payload.projects.map(prepareSearchProject);
        mergeProjectsIntoIndexCache(state.projects);
        // Mark requested keys present even when empty so peer fetch does not loop.
        keys.forEach((key) => {
          if (!Object.prototype.hasOwnProperty.call(state.indexBySource, key)) {
            state.indexBySource[key] = [];
          }
        });
        state.itemCount = Number(payload.item_count ?? state.itemCount);
        state.projectCount = Number(payload.project_count ?? state.projects.length);
        syncSelectedProjectCount();
        state.lastSynced = payload.last_synced_at || state.lastSynced;
        state.lastStatus = payload.last_sync_status || state.lastStatus;
        if (typeof payload.favorite_count === 'number') {
          state.favoriteCount = payload.favorite_count;
          updateFavoriteToggleText();
        }
        if (Array.isArray(payload.favorite_sources)) {
          setFavoriteSources(payload.favorite_sources);
        }
        state.allTags = normalizeTagList(payload.tags);
        if (typeof payload.can_edit_tags === 'boolean') {
          state.canEditTags = payload.can_edit_tags;
          searchRoot.dataset.canEditTags = payload.can_edit_tags ? '1' : '0';
        }
        if (typeof payload.can_archive === 'boolean') {
          state.canArchive = payload.can_archive;
          searchRoot.dataset.canArchive = payload.can_archive ? '1' : '0';
        }
        state.loadingIndex = false;
        state.ready = true;
        populatePersonFilter();
        ensurePeerIndexes();
        applySearch({ resetPage: false, syncInputs: true });
      })
      .catch((error) => {
        state.loadingIndex = false;
        tbody.innerHTML = `<tr class="sharepoint-empty-row"><td colspan="${listVisibleColspan()}">${escapeHtml(error.message || 'Search index failed.')} Showing server results — refresh to retry live search.</td></tr>`;
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
    state.page = 1;
    window.dispatchEvent(new CustomEvent('riskregister:sp-scopes', { detail: { keys: [...state.scopeKeys] } }));
    loadIndex();
  };

  form?.addEventListener('submit', (event) => {
    event.preventDefault();
    state.query = input?.value || '';
    hideSuggestions();
    scheduleTypedSearch.flush();
    scheduleRememberRecent.flush();
  });

  const setListSort = (key) => {
    const next = LIST_SORT_KEYS.has(key) ? key : 'name';
    state.userSort = true;
    if (state.sortKey === next) {
      state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
    } else {
      state.sortKey = next;
      state.sortDir = LIST_SORT_DEFAULT_DIR[next] || 'asc';
    }
    applySearch({ resetPage: true });
  };

  listTable?.querySelector('thead')?.addEventListener('click', (event) => {
    const btn = event.target.closest('.sp-dialog-sort-btn[data-sort]');
    if (!btn || !listTable.contains(btn)) return;
    event.preventDefault();
    setListSort(btn.getAttribute('data-sort') || 'name');
  });

  const scheduleColumnFilter = debouncePaint(() => {
    applySearch({ resetPage: true, syncInputs: false });
  }, 70);

  listFilterRow?.addEventListener('input', (event) => {
    const filterInput = event.target.closest('[data-filter]');
    if (!filterInput) return;
    const key = filterInput.getAttribute('data-filter') || '';
    if (!Object.prototype.hasOwnProperty.call(state.filters, key)) return;
    state.filters[key] = filterInput.value;
    scheduleColumnFilter();
  });

  listFilterToggle?.addEventListener('click', () => {
    state.filtersOpen = !state.filtersOpen;
    try {
      localStorage.setItem(STORAGE.listFilters, state.filtersOpen ? '1' : '0');
    } catch {
      /* ignore */
    }
    syncListFilterUi();
  });

  listFilterClear?.addEventListener('click', () => {
    state.filters = emptyListFilters();
    applySearch({ resetPage: true });
  });

  syncListFilterUi();
  syncListSortHeaders();

  recentRoot?.addEventListener('click', (event) => {
    const removeBtn = event.target.closest('[data-recent-remove]');
    if (removeBtn) {
      event.preventDefault();
      removeRecentSearch(removeBtn.getAttribute('data-recent-remove') || '');
      return;
    }
    const chip = event.target.closest('[data-recent-query]');
    if (!chip) return;
    event.preventDefault();
    applyRecentSearch(chip.getAttribute('data-recent-query') || '');
  });

  recentClearBtn?.addEventListener('click', () => {
    writeRecentSearches([]);
    recentPaintedKey = '';
    renderRecentSearches();
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
    scheduleRememberRecent.cancel();
    hideSuggestions();
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

  favoritesToggle?.addEventListener('click', () => {
    state.showFavorites = !state.showFavorites;
    try {
      localStorage.setItem(STORAGE.favorites, state.showFavorites ? '1' : '0');
    } catch {
      /* ignore */
    }
    applySearch({ resetPage: true });
  });

  archivedToggle?.addEventListener('click', () => {
    state.showArchived = !state.showArchived;
    try {
      localStorage.setItem(STORAGE.archived, state.showArchived ? '1' : '0');
    } catch {
      /* ignore */
    }
    searchRoot.dataset.showArchived = state.showArchived ? '1' : '0';
    state.projects = state.projects.map((project) => prepareSearchProject(project));
    applySearch({ resetPage: true });
    const dialog = document.getElementById('sharepoint-project-dialog');
    if (dialog?.open) {
      dialog.querySelector('#sharepoint-project-dialog-search')?.dispatchEvent(new Event('input'));
    }
  });

  suggestToggle?.addEventListener('click', () => {
    state.suggestEnabled = !state.suggestEnabled;
    try {
      localStorage.setItem(STORAGE.suggest, state.suggestEnabled ? '1' : '0');
    } catch {
      /* ignore */
    }
    updateControlsVisibility();
    if (state.suggestEnabled && document.activeElement === input && (input.value || '').trim()) {
      renderSuggestions();
    } else {
      hideSuggestions();
    }
  });

  wordModeGroup?.querySelectorAll('[data-word-mode]').forEach((btn) => {
    btn.addEventListener('click', () => {
      state.wordMode = btn.getAttribute('data-word-mode') === 'or' ? 'or' : 'and';
      localStorage.setItem(STORAGE.wordMode, state.wordMode);
      applySearch();
    });
  });

  matchScopeGroup?.querySelectorAll('[data-match-scope]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const next = btn.getAttribute('data-match-scope') || 'all';
      state.matchScope = MATCH_SCOPES.has(next) ? next : 'all';
      if (state.matchScope !== 'all') setAdvancedOpen(true);
      applySearch({ resetPage: true });
    });
  });

  advancedToggle?.addEventListener('click', () => {
    setAdvancedOpen(!state.advancedOpen);
  });

  typeChipsRoot?.addEventListener('click', (event) => {
    const chip = event.target.closest('[data-type-chip]');
    if (!chip) return;
    const key = chip.getAttribute('data-type-chip') || '';
    if (!TYPE_CHIP_KEYS.has(key)) return;
    if (state.types.includes(key)) {
      state.types = state.types.filter((item) => item !== key);
    } else {
      state.types = [...state.types, key];
    }
    applySearch({ resetPage: true });
  });

  datePresetEl?.addEventListener('change', () => {
    const next = datePresetEl.value || '';
    state.datePreset = DATE_PRESETS.has(next) ? next : '';
    if (state.datePreset) setAdvancedOpen(true);
    applySearch({ resetPage: true });
  });
  dateFromEl?.addEventListener('change', () => {
    state.dateFrom = dateFromEl.value || '';
    if (state.datePreset !== 'custom') state.datePreset = 'custom';
    setAdvancedOpen(true);
    applySearch({ resetPage: true });
  });
  dateToEl?.addEventListener('change', () => {
    state.dateTo = dateToEl.value || '';
    if (state.datePreset !== 'custom') state.datePreset = 'custom';
    setAdvancedOpen(true);
    applySearch({ resetPage: true });
  });
  personFilterEl?.addEventListener('change', () => {
    state.who = personFilterEl.value || '';
    if (state.who) setAdvancedOpen(true);
    applySearch({ resetPage: true });
  });
  presenceFilterEl?.addEventListener('change', () => {
    const next = presenceFilterEl.value || 'any';
    state.presence = PRESENCE_MODES.has(next) ? next : 'any';
    if (state.presence !== 'any') setAdvancedOpen(true);
    applySearch({ resetPage: true });
  });
  missingSourceEl?.addEventListener('change', () => {
    state.missingSource = missingSourceEl.value || '';
    setAdvancedOpen(true);
    applySearch({ resetPage: true });
  });
  hasFilterEl?.addEventListener('change', () => {
    const next = hasFilterEl.value || '';
    state.has = HAS_LACK_KEYS.has(next) ? next : '';
    if (state.has) setAdvancedOpen(true);
    applySearch({ resetPage: true });
  });
  lacksFilterEl?.addEventListener('change', () => {
    const next = lacksFilterEl.value || '';
    state.lacks = HAS_LACK_KEYS.has(next) ? next : '';
    if (state.lacks) setAdvancedOpen(true);
    applySearch({ resetPage: true });
  });

  saveSearchBtn?.addEventListener('click', () => saveCurrentSearch());
  exportCsvBtn?.addEventListener('click', () => exportFilteredCsv());

  savedRoot?.addEventListener('click', (event) => {
    const removeBtn = event.target.closest('[data-saved-remove]');
    if (removeBtn) {
      event.preventDefault();
      removeSavedSearch(removeBtn.getAttribute('data-saved-remove') || '');
      return;
    }
    const chip = event.target.closest('[data-saved-id]');
    if (!chip) return;
    event.preventDefault();
    applySavedSearch(chip.getAttribute('data-saved-id') || '');
  });

  suggestEl?.addEventListener('mousedown', (event) => {
    const btn = event.target.closest('[data-suggest-index]');
    if (!btn) return;
    event.preventDefault();
    const index = Number(btn.getAttribute('data-suggest-index'));
    applySuggestion(state.suggestItems[index]);
  });

  input?.addEventListener('keydown', (event) => {
    if (!state.suggestOpen || !state.suggestItems.length) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      state.suggestIndex = (state.suggestIndex + 1) % state.suggestItems.length;
      suggestEl
        ?.querySelectorAll('.sp-suggest-item')
        .forEach((el, idx) => {
          const active = idx === state.suggestIndex;
          el.classList.toggle('is-active', active);
          el.setAttribute('aria-selected', active ? 'true' : 'false');
        });
      return;
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault();
      state.suggestIndex =
        (state.suggestIndex - 1 + state.suggestItems.length) % state.suggestItems.length;
      suggestEl
        ?.querySelectorAll('.sp-suggest-item')
        .forEach((el, idx) => {
          const active = idx === state.suggestIndex;
          el.classList.toggle('is-active', active);
          el.setAttribute('aria-selected', active ? 'true' : 'false');
        });
      return;
    }
    if (event.key === 'Enter' && state.suggestIndex >= 0) {
      event.preventDefault();
      applySuggestion(state.suggestItems[state.suggestIndex]);
      return;
    }
    if (event.key === 'Escape') {
      event.preventDefault();
      hideSuggestions();
    }
  });

  input?.addEventListener('focus', () => {
    if (!state.suggestEnabled) return;
    if ((input.value || '').trim()) scheduleSuggestions();
  });
  input?.addEventListener('blur', () => {
    window.setTimeout(() => hideSuggestions(), 120);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey) {
      if (isTypingTarget(event.target)) return;
      event.preventDefault();
      input?.focus();
      input?.select();
      return;
    }
    if (event.key === 'Escape') {
      if (state.suggestOpen) {
        hideSuggestions();
        return;
      }
      if (document.activeElement === input && state.query) {
        state.query = '';
        applySearch({ resetPage: true, syncInputs: true });
      }
    }
  });

  input?.addEventListener('input', () => {
    if (searchComposing) return;
    if (!state.suggestEnabled) {
      hideSuggestions();
      return;
    }
    scheduleSuggestions();
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
    if (publicShare) return;
    const sourceQs = state.sourceKey ? `&source=${encodeURIComponent(state.sourceKey)}` : '';
    const viewQs = searchRoot.getAttribute('data-solo') === '1' ? '&view=catalog' : '';
    const saveUrl = `sharepoint.php?per=${encodeURIComponent(String(state.perPage))}${sourceQs}${viewQs}#sharepoint-search`;
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
  window.dispatchEvent(new CustomEvent('riskregister:sp-scopes', { detail: { keys: [...state.scopeKeys] } }));
  syncCompareBar();
  syncActiveCatalogChrome();
  if (state.query.trim().length >= RECENT_MIN_LEN) {
    rememberRecentSearch(state.query);
  } else {
    renderRecentSearches();
  }

  const catalogPageUrl = () => {
    const url = new URL(publicShare ? 'catalog-share.php' : 'sharepoint.php', window.location.href);
    url.search = '';
    url.hash = '';
    if (publicShare) {
      const token = (searchRoot.getAttribute('data-share-token') || '').trim();
      if (token) url.searchParams.set('t', token);
    } else {
      url.searchParams.set('view', 'catalog');
    }
    if (state.sourceKey) url.searchParams.set('source', state.sourceKey);
    if (state.scopeKeys.length > 1) {
      url.searchParams.set('sources', state.scopeKeys.join(','));
    }
    const q = String(state.query || '').trim();
    if (q) url.searchParams.set('q', q);
    if (state.perPage && Number(state.perPage) !== 25) {
      url.searchParams.set('per', String(state.perPage));
    }
    if (state.page > 1) url.searchParams.set('page', String(state.page));
    return url.toString();
  };

  document.querySelectorAll('.sharepoint-open-catalog[data-source-key]').forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const key = (btn.getAttribute('data-source-key') || '').trim();
      if (!key) return;
      setScopes([key]);
      document.getElementById('sharepoint-search')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  document.getElementById('sp-catalog-open-tab')?.addEventListener('click', (event) => {
    event.stopPropagation();
    const link = event.currentTarget;
    if (link instanceof HTMLAnchorElement) {
      link.href = catalogPageUrl();
    }
  });

  document.getElementById('sp-catalog-open-window')?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    const features =
      'popup=yes,width=1480,height=920,left=40,top=40,menubar=no,toolbar=no,location=yes,status=yes,resizable=yes,scrollbars=yes';
    const win = window.open(catalogPageUrl(), 'riskregister-sp-catalog', features);
    if (win) {
      try {
        win.opener = null;
      } catch {
        /* ignore */
      }
      win.focus();
    }
  });

  loadIndex();

  document.querySelectorAll('.sharepoint-archive-source-form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const willArchive = form.querySelector('input[name="archived"]')?.value === '1';
      if (!willArchive) return;
      const title =
        form.closest('[data-source-key]')?.querySelector('.sharepoint-source-card-title, strong')
          ?.textContent || 'this catalog';
      if (
        !window.confirm(
          `Archive ${title.trim()}? It will be hidden from the catalog dashboard until you unarchive it.`
        )
      ) {
        event.preventDefault();
      }
    });
  });
})();

(() => {
  const VIEW_KEY = 'ra-sp-folders-view';
  const FAV_KEY = 'ra-sp-folders-fav';
  const ADMIN_KEY = 'ra-sp-admin-open';
  const ADD_KEY = 'ra-sp-add-folder-open';
  const FOLDERS_KEY = 'ra-sp-folders-open';
  const allowedViews = ['comfort', 'compact', 'table'];
  const normalizeFoldersView = (view) => {
    if (view === 'cards') return 'comfort';
    return allowedViews.includes(view) ? view : 'compact';
  };
  const panel = document.getElementById('sharepoint-sources');
  const grid = document.getElementById('sharepoint-sources-grid');
  const tableWrap = document.getElementById('sharepoint-sources-table-wrap');
  const toggle = panel?.querySelector('.sharepoint-folders-view-toggle');
  const favToggle = document.getElementById('sharepoint-folders-fav-toggle');
  const favEmpty = document.getElementById('sharepoint-folders-fav-empty');
  const foldersSolo = panel?.getAttribute('data-solo') === '1';

  const foldersPageUrl = () => {
    const url = new URL('sharepoint.php', window.location.href);
    url.search = '';
    url.hash = '';
    url.searchParams.set('view', 'folders');
    return url.toString();
  };

  const foldersCsrf = () => panel?.getAttribute('data-csrf') || '';

  const applySourceFavoriteUi = (sourceKey, favorited) => {
    document
      .querySelectorAll(`.sharepoint-source-card[data-source-key="${CSS.escape(sourceKey)}"], .sharepoint-source-row[data-source-key="${CSS.escape(sourceKey)}"]`)
      .forEach((el) => {
        el.classList.toggle('is-favorite', favorited);
        el.setAttribute('data-favorited', favorited ? '1' : '0');
      });
    document
      .querySelectorAll(`.sp-favorite-btn[data-scope="source"][data-source-key="${CSS.escape(sourceKey)}"]`)
      .forEach((btn) => {
        btn.classList.toggle('is-on', favorited);
        btn.setAttribute('data-favorited', favorited ? '1' : '0');
        btn.setAttribute('aria-pressed', favorited ? 'true' : 'false');
        const label = favorited ? 'Remove from favorites' : 'Add to favorites';
        btn.setAttribute('title', label);
        btn.setAttribute('aria-label', label);
        btn.textContent = favorited ? '★' : '☆';
      });
    document
      .querySelectorAll(`#sharepoint-search-scopes .sharepoint-scope-chip[data-source-key="${CSS.escape(sourceKey)}"]`)
      .forEach((chip) => {
        chip.classList.toggle('is-favorite', favorited);
        chip.setAttribute('data-favorited', favorited ? '1' : '0');
        let star = chip.querySelector('.sharepoint-scope-favorite');
        if (!star) {
          star = document.createElement('span');
          star.className = 'sharepoint-scope-favorite';
          star.textContent = '★';
          star.title = 'Favorite catalog';
          star.setAttribute('aria-label', 'Favorite catalog');
          const hitCount = chip.querySelector('.sharepoint-scope-hit-count');
          if (hitCount) {
            hitCount.before(star);
          } else {
            chip.querySelector('.sharepoint-scope-chip-main')?.appendChild(star);
          }
        }
        star.hidden = !favorited;
      });
    try {
      window.RiskRegisterSharePoint?.syncSourceFavorite?.(sourceKey, favorited);
    } catch {
      /* ignore */
    }
  };

  const applyFoldersFavFilter = (showFav) => {
    if (!panel) return;
    panel.setAttribute('data-folders-fav', showFav ? '1' : '0');
    favToggle?.classList.toggle('is-active', showFav);
    favToggle?.setAttribute('aria-pressed', showFav ? 'true' : 'false');
    panel.querySelectorAll('.sharepoint-source-card[data-source-key], .sharepoint-source-row[data-source-key]').forEach((el) => {
      const isFav = el.getAttribute('data-favorited') === '1';
      const hide = showFav && !isFav;
      el.hidden = hide;
      el.classList.toggle('is-fav-hidden', hide);
    });
    // Cards and rows are duplicates of the same sources — count unique keys.
    const visibleKeys = new Set();
    panel.querySelectorAll('.sharepoint-source-card[data-source-key]:not([hidden])').forEach((el) => {
      visibleKeys.add(el.getAttribute('data-source-key') || '');
    });
    const empty = showFav && visibleKeys.size === 0;
    if (favEmpty) {
      favEmpty.hidden = !empty;
      favEmpty.classList.toggle('is-hidden', !empty);
    }
    try {
      localStorage.setItem(FAV_KEY, showFav ? '1' : '0');
    } catch {
      /* ignore */
    }
  };

  const postSourceFavorite = async (sourceKey, favorited) => {
    const body = new URLSearchParams();
    body.set('csrf_token', foldersCsrf());
    body.set('action', 'set_favorite');
    body.set('ajax', '1');
    body.set('scope', 'source');
    body.set('source_key', sourceKey);
    body.set('project_name', '');
    body.set('favorited', favorited ? '1' : '0');
    const response = await fetch('sharepoint.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      },
      body: body.toString(),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload?.ok) {
      throw new Error(payload?.error || 'Unable to update favorite.');
    }
    return payload;
  };

  panel?.querySelectorAll('[data-no-toggle]').forEach((el) => {
    el.addEventListener('click', (event) => event.stopPropagation());
    el.addEventListener('pointerdown', (event) => event.stopPropagation());
  });

  document.getElementById('sp-folders-open-tab')?.addEventListener('click', (event) => {
    event.stopPropagation();
  });

  document.getElementById('sp-folders-open-window')?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    const features =
      'popup=yes,width=1480,height=920,left=40,top=40,menubar=no,toolbar=no,location=yes,status=yes,resizable=yes,scrollbars=yes';
    const win = window.open(foldersPageUrl(), 'riskregister-sp-folders', features);
    if (win) {
      try {
        win.opener = null;
      } catch {
        /* ignore */
      }
      win.focus();
    }
  });

  const applyFoldersView = (view) => {
    const next = normalizeFoldersView(view);
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
      return normalizeFoldersView(localStorage.getItem(VIEW_KEY) || 'compact');
    } catch {
      return 'compact';
    }
  })();
  applyFoldersView(savedView);

  const savedFav = (() => {
    try {
      return localStorage.getItem(FAV_KEY) === '1';
    } catch {
      return false;
    }
  })();
  applyFoldersFavFilter(savedFav);

  favToggle?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    const next = favToggle.getAttribute('aria-pressed') !== 'true';
    applyFoldersFavFilter(next);
  });

  panel?.addEventListener('click', async (event) => {
    const btn = event.target.closest('.sp-favorite-btn[data-scope="source"]');
    if (!btn || !panel.contains(btn)) return;
    event.preventDefault();
    event.stopPropagation();
    if (btn.disabled) return;
    const sourceKey = btn.getAttribute('data-source-key') || '';
    if (!sourceKey) return;
    const nextFavorited = btn.getAttribute('data-favorited') !== '1';
    btn.disabled = true;
    try {
      await postSourceFavorite(sourceKey, nextFavorited);
      applySourceFavoriteUi(sourceKey, nextFavorited);
      const showingFav = panel.getAttribute('data-folders-fav') === '1';
      if (showingFav) applyFoldersFavFilter(true);
    } catch (error) {
      window.alert(error.message || 'Unable to update favorite.');
    } finally {
      btn.disabled = false;
    }
  });

  toggle?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    const btn = event.target.closest('[data-folders-view]');
    if (!btn) return;
    applyFoldersView(btn.getAttribute('data-folders-view') || 'compact');
  });

  const foldersSummary = panel?.querySelector('.sharepoint-sources-summary');
  foldersSummary?.addEventListener('click', (event) => {
    if (event.target.closest('[data-no-toggle], a, button, input, select, label')) {
      event.preventDefault();
    }
  });

  const foldersShell = document.getElementById('sharepoint-sources-shell');
  if (foldersShell) {
    if (foldersSolo) {
      foldersShell.open = true;
      foldersShell.addEventListener('toggle', () => {
        if (!foldersShell.open) foldersShell.open = true;
      });
    } else {
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

(() => {
  const SEARCH_KEY = 'ra-sp-catalog-search-open';
  const TABLE_KEY = 'ra-sp-catalog-table-open';
  const searchRoot = document.getElementById('sharepoint-search');
  const searchShell = document.getElementById('sharepoint-catalog-shell');
  const tableShell = document.getElementById('sharepoint-catalog-table-shell');
  const catalogSolo = searchRoot?.getAttribute('data-solo') === '1';

  const bindCatalogShell = (shell, storageKey, forceOpen) => {
    if (!shell) return;
    const summary = shell.querySelector(':scope > summary');
    shell.querySelectorAll('[data-no-toggle]').forEach((el) => {
      el.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
      });
      el.addEventListener('pointerdown', (event) => event.stopPropagation());
    });
    summary?.addEventListener('click', (event) => {
      if (event.target.closest('[data-no-toggle], a, button, input, select, label')) {
        event.preventDefault();
      }
    });
    if (catalogSolo) {
      shell.open = true;
      shell.addEventListener('toggle', () => {
        if (!shell.open) shell.open = true;
      });
      return;
    }
    try {
      const saved = localStorage.getItem(storageKey);
      if (forceOpen || saved === '1') shell.open = true;
      else if (saved === '0') shell.open = false;
    } catch {
      /* ignore */
    }
    shell.addEventListener('toggle', () => {
      try {
        localStorage.setItem(storageKey, shell.open ? '1' : '0');
      } catch {
        /* ignore */
      }
    });
  };

  const hash = window.location.hash;
  bindCatalogShell(searchShell, SEARCH_KEY, hash === '#sharepoint-search');
  bindCatalogShell(tableShell, TABLE_KEY, hash === '#sharepoint-table-card');
})();

(() => {
  const copyBtn = document.getElementById('btn-copy-catalog-share-link');
  const urlInput = document.getElementById('catalog-share-link-url');
  const statusEl = document.getElementById('catalog-share-link-copy-status');
  if (!copyBtn || !urlInput) return;
  copyBtn.addEventListener('click', async () => {
    const value = urlInput.value;
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(value);
      } else {
        urlInput.select();
        document.execCommand('copy');
      }
      if (statusEl) {
        statusEl.hidden = false;
        statusEl.textContent = 'Link copied to clipboard.';
      }
    } catch {
      urlInput.select();
      if (statusEl) {
        statusEl.hidden = false;
        statusEl.textContent = 'Select the link and press Ctrl+C to copy.';
      }
    }
  });
})();
