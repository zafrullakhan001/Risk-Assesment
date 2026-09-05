/**
 * Template Mermaid fullscreen viewer — ported from MD (mermaidDiagramUi)
 * gear menu + native fullscreen + SVG-width zoom (sharp) + pan.
 */
(() => {
  const ELK_CDN = 'https://cdn.jsdelivr.net/npm/@mermaid-js/layout-elk@0.2.1/dist/mermaid-layout-elk.esm.min.mjs';
  const MERMAID_CDN = 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';
  const ZOOM_MIN = 0.25;
  const ZOOM_MAX = 5;
  const ZOOM_STEP = 0.1;
  const DEFAULT_ZOOM = 1;
  const ZOOM_PRESETS = [50, 75, 100, 125, 150];
  const DIRECTIONS = ['TB', 'BT', 'LR', 'RL'];

  let mermaidApi = null;
  let mermaidReady = false;
  let mermaidLoading = null;
  let elkRegistered = false;

  const clampZoom = (z) => Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, z));

  const uid = () => `tm-${Math.random().toString(36).slice(2, 10)}`;

  const applyMermaidOptions = (source, layout, direction) => {
    let code = (source || '').trim();
    if (code === '') return '';

    const useElk = String(layout || '').toLowerCase() === 'elk';
    let dir = String(direction || 'TB').toUpperCase();
    if (!DIRECTIONS.includes(dir) && dir !== 'TD') dir = 'TB';

    code = code.replace(/^---\s*\nconfig:\s*\n(?:[ \t].*\n)*---\s*\n?/u, '');
    code = code.replace(/^%%\{init:[\s\S]*?\}%%\s*/u, '');
    code = code.trim();

    const keyword = useElk ? 'flowchart-elk' : 'flowchart';
    if (/^(flowchart(?:-elk)?|graph)\s+(TB|TD|BT|LR|RL)\b/im.test(code)) {
      code = code.replace(/^(flowchart(?:-elk)?|graph)\s+(TB|TD|BT|LR|RL)\b/im, `${keyword} ${dir}`);
    } else if (/^(flowchart(?:-elk)?|graph)\b/im.test(code)) {
      code = code.replace(/^(flowchart(?:-elk)?|graph)\b/im, `${keyword} ${dir}`);
    } else {
      code = `${keyword} ${dir}\n${code}`;
    }

    if (useElk) {
      code = `---\nconfig:\n  layout: elk\n---\n${code}`;
    }
    return code;
  };

  const parseOptions = (source) => {
    let layout = 'default';
    let direction = 'TB';
    if (
      /^\s*flowchart-elk\b/im.test(source)
      || /^\s*layout:\s*elk\b/im.test(source)
      || /defaultRenderer['"]?\s*:\s*['"]elk['"]/i.test(source)
    ) {
      layout = 'elk';
    }
    const m = source.match(/^(?:flowchart(?:-elk)?|graph)\s+(TB|TD|BT|LR|RL)\b/im);
    if (m) direction = m[1].toUpperCase();
    return { layout, direction };
  };

  const getSvgDimensions = (svgEl) => {
    const wAttr = svgEl.getAttribute('width') || '';
    const hAttr = svgEl.getAttribute('height') || '';
    let w = wAttr.includes('%') ? 0 : parseFloat(wAttr);
    let h = hAttr.includes('%') ? 0 : parseFloat(hAttr);
    if (!w || !h) {
      const vb = svgEl.getAttribute('viewBox');
      if (vb) {
        const p = vb.trim().split(/[\s,]+/);
        if (p.length >= 4) {
          w = parseFloat(p[2]);
          h = parseFloat(p[3]);
        }
      }
    }
    if (!w || !h) {
      const r = svgEl.getBoundingClientRect();
      w = r.width || 800;
      h = r.height || 600;
    }
    return { width: Math.ceil(w) || 800, height: Math.ceil(h) || 600 };
  };

  const readSvgViewBoxSize = (svg) => {
    const vb = svg.getAttribute('viewBox');
    if (vb) {
      const p = vb.trim().split(/[\s,]+/);
      if (p.length >= 4) {
        const w = parseFloat(p[2]);
        const h = parseFloat(p[3]);
        if (w > 0 && h > 0) return { w, h };
      }
    }
    const d = getSvgDimensions(svg);
    return { w: d.width, h: d.height };
  };

  const ensureMermaid = async () => {
    if (mermaidReady && mermaidApi) return mermaidApi;
    if (mermaidLoading) return mermaidLoading;

    mermaidLoading = (async () => {
      const [{ default: mermaid }, elkModule] = await Promise.all([
        import(MERMAID_CDN),
        import(ELK_CDN),
      ]);
      const elkLayouts = elkModule.default || elkModule;
      if (!elkRegistered && typeof mermaid.registerLayoutLoaders === 'function' && elkLayouts) {
        mermaid.registerLayoutLoaders(elkLayouts);
        elkRegistered = true;
      }
      mermaid.initialize({
        startOnLoad: false,
        securityLevel: 'strict',
        theme: document.documentElement.dataset.theme === 'indigo' ? 'dark' : 'default',
        htmlLabels: true,
        markdownAutoWrap: true,
        flowchart: {
          htmlLabels: true,
          curve: 'linear',
          wrappingWidth: 200,
        },
      });
      mermaidApi = mermaid;
      window.mermaid = mermaid;
      mermaidReady = true;
      return mermaid;
    })().catch((err) => {
      mermaidLoading = null;
      throw err;
    });

    return mermaidLoading;
  };

  const LABEL_HEIGHT_SLACK = 4;
  const EDGE_LABEL_WIDTH_SLACK = 8;
  const CLUSTER_LABEL_HEIGHT_SLACK = 8;

  /**
   * Grow edge / cluster label foreignObject width so nowrap text is not clipped.
   * @param {Element} scopeG
   * @param {Element | null} rectEl
   */
  const patchForeignObjectWidth = (scopeG, rectEl) => {
    const fo = scopeG.querySelector('foreignObject');
    if (!fo) return;
    const inner = fo.firstElementChild;
    if (!(inner instanceof HTMLElement)) return;
    const savedW = parseFloat(fo.getAttribute('width') || '');
    if (!Number.isFinite(savedW) || savedW <= 0) return;

    fo.setAttribute('width', '9999');
    void fo.offsetWidth;
    const naturalW = Math.ceil(inner.scrollWidth) + EDGE_LABEL_WIDTH_SLACK;
    const newW = Math.max(savedW, naturalW);
    fo.setAttribute('width', String(newW));
    const delta = newW - savedW;
    if (delta > 0.5 && rectEl) {
      const rw = parseFloat(rectEl.getAttribute('width') || '');
      if (Number.isFinite(rw) && rw > 0) {
        rectEl.setAttribute('width', String(rw + delta));
      }
    }
  };

  /**
   * Re-measure foreignObject height after wrapping so multi-line labels are not clipped.
   * @param {Element} scopeG
   * @param {Element | null} rectEl
   * @param {number} [heightSlack]
   */
  const patchForeignObjectHeight = (scopeG, rectEl, heightSlack = LABEL_HEIGHT_SLACK) => {
    const fo = scopeG.querySelector('foreignObject');
    if (!fo) return;
    const inner = fo.firstElementChild;
    if (!(inner instanceof HTMLElement)) return;
    const savedH = parseFloat(fo.getAttribute('height') || '');
    if (!Number.isFinite(savedH) || savedH <= 0) return;

    fo.setAttribute('height', '9999');
    void fo.offsetHeight;
    const natural = Math.ceil(inner.scrollHeight) + heightSlack;
    const newH = Math.max(savedH, natural);
    fo.setAttribute('height', String(newH));
    const delta = newH - savedH;
    if (delta > 0.5 && rectEl) {
      const rh = parseFloat(rectEl.getAttribute('height') || '');
      if (Number.isFinite(rh) && rh > 0) {
        rectEl.setAttribute('height', String(rh + delta));
      }
    }
  };

  /**
   * Fix clipped flowchart HTML labels (wrap + grow foreignObject / node rect).
   * @param {SVGElement | Element | null} svg
   */
  const patchMermaidFlowchartLabels = (svg) => {
    if (!svg || typeof svg.querySelectorAll !== 'function') return;

    svg.querySelectorAll('g.node').forEach((nodeG) => {
      const fo = nodeG.querySelector('foreignObject');
      if (fo) {
        fo.setAttribute('overflow', 'visible');
        const label = fo.querySelector('.nodeLabel, div, span, p');
        if (label instanceof HTMLElement) {
          label.style.whiteSpace = 'normal';
          label.style.overflowWrap = 'anywhere';
          label.style.wordBreak = 'break-word';
          label.style.overflow = 'visible';
        }
      }
      const rect = nodeG.querySelector('rect.label-container')
        || nodeG.querySelector('rect.basic.label-container')
        || nodeG.querySelector(':scope > rect.basic')
        || nodeG.querySelector(':scope > rect');
      patchForeignObjectHeight(nodeG, rect);
    });

    svg.querySelectorAll('g.edgeLabels g.edgeLabel, g.edgeLabel').forEach((edgeG) => {
      const rect = edgeG.querySelector('g.label > rect') || edgeG.querySelector('rect');
      patchForeignObjectWidth(edgeG, rect);
      patchForeignObjectHeight(edgeG, rect);
    });

    svg.querySelectorAll('g.cluster .cluster-label').forEach((labG) => {
      const rect = labG.querySelector('rect');
      patchForeignObjectWidth(labG, rect);
      patchForeignObjectHeight(labG, rect, CLUSTER_LABEL_HEIGHT_SLACK);
    });
  };

  const schedulePatchMermaidLabels = (root) => {
    if (!(root instanceof Element)) return;
    requestAnimationFrame(() => {
      const svg = root.querySelector('svg');
      patchMermaidFlowchartLabels(svg);
      requestAnimationFrame(() => {
        patchMermaidFlowchartLabels(root.querySelector('svg'));
      });
    });
  };

  const openMermaidLive = (source) => {
    const state = {
      code: (source || '').trim(),
      mermaid: { theme: document.documentElement.dataset.theme === 'indigo' ? 'dark' : 'default' },
      updateEditor: true,
      autoSync: true,
      updateDiagram: true,
    };
    const encoded = btoa(unescape(encodeURIComponent(JSON.stringify(state))));
    window.open(`https://mermaid.live/edit#base64:${encoded}`, '_blank', 'noopener,noreferrer');
  };

  const copyText = async (text, button) => {
    const label = button.textContent;
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.left = '-9999px';
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        document.body.removeChild(area);
      }
      button.textContent = 'Copied';
      window.setTimeout(() => { button.textContent = label; }, 1200);
    } catch (_e) {
      button.textContent = 'Copy failed';
      window.setTimeout(() => { button.textContent = label; }, 1200);
    }
  };

  /**
   * @param {string} originalCode
   * @param {{ title?: string }} [opts]
   */
  const openFullscreen = async (originalCode, opts = {}) => {
    const title = (opts.title || 'Mermaid diagram').trim() || 'Mermaid diagram';
    const baseCode = (originalCode || '').trim();
    if (!baseCode) return;

    const mermaid = await ensureMermaid();
    const initial = parseOptions(baseCode);
    let layout = initial.layout;
    let direction = initial.direction === 'TD' ? 'TB' : initial.direction;
    let zoomLevel = DEFAULT_ZOOM;
    let panModeEnabled = true;
    let isPanning = false;
    let panOffset = { x: 0, y: 0 };
    let showingCode = false;
    let applyZoom = () => {};

    const fsEl = document.createElement('div');
    fsEl.className = 'mermaid-fs-full';
    fsEl.setAttribute('role', 'dialog');
    fsEl.setAttribute('aria-label', title);

    const fsDiagramView = document.createElement('div');
    fsDiagramView.className = 'mermaid-fs-diagram';

    const zoomHost = document.createElement('div');
    zoomHost.className = 'mermaid-zoom-host';
    fsDiagramView.appendChild(zoomHost);

    const fsCodeView = document.createElement('pre');
    fsCodeView.className = 'mermaid-code mermaid-fs-code';
    fsCodeView.hidden = true;
    fsCodeView.textContent = baseCode;

    const renderDiagram = async () => {
      const code = applyMermaidOptions(baseCode, layout, direction);
      fsCodeView.textContent = code;
      const svgId = `mermaid-svg-${uid()}`;
      const { svg } = await mermaid.render(svgId, code);
      zoomHost.innerHTML = svg;
      const svgEl = zoomHost.querySelector('svg');
      if (svgEl) {
        patchMermaidFlowchartLabels(svgEl);
        let w;
        let h;
        try {
          const bbox = svgEl.getBBox();
          w = Math.ceil(Math.max(bbox.width + bbox.x, bbox.width));
          h = Math.ceil(Math.max(bbox.height + bbox.y, bbox.height));
        } catch (_e) {
          const dims = getSvgDimensions(svgEl);
          w = dims.width;
          h = dims.height;
        }
        if (!w || !h) {
          const dims = getSvgDimensions(svgEl);
          w = dims.width;
          h = dims.height;
        }
        svgEl.removeAttribute('style');
        svgEl.setAttribute('viewBox', `0 0 ${w} ${h}`);
        svgEl.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        svgEl.setAttribute('shape-rendering', 'geometricPrecision');
        svgEl.setAttribute('text-rendering', 'geometricPrecision');
        schedulePatchMermaidLabels(zoomHost);
      }
      applyZoom();
    };

    // ── Gear controls (MD pattern) ─────────────────────────────────────────
    const controls = document.createElement('div');
    controls.className = 'mermaid-controls';

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'mermaid-controls__trigger';
    trigger.setAttribute('aria-label', 'Diagram settings');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.innerHTML = '&#9881;';

    const panel = document.createElement('div');
    panel.className = 'mermaid-controls__panel';
    panel.hidden = true;

    const titleEl = document.createElement('div');
    titleEl.className = 'mcp-label mcp-label--section';
    titleEl.textContent = title;
    panel.appendChild(titleEl);

    const makeRow = () => {
      const row = document.createElement('div');
      row.className = 'mcp-row';
      return row;
    };
    const makeLabel = (text) => {
      const lbl = document.createElement('span');
      lbl.className = 'mcp-label';
      lbl.textContent = text;
      return lbl;
    };

    // Layout
    const layoutRow = makeRow();
    layoutRow.appendChild(makeLabel('Layout'));
    const layoutGroup = document.createElement('div');
    layoutGroup.className = 'mcp-dir-group mcp-dir-group--2';
    [['default', 'Default'], ['elk', 'ELK']].forEach(([value, label]) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `mcp-dir-btn mcp-dir-btn--layout${layout === value ? ' mcp-dir-btn--active' : ''}`;
      btn.setAttribute('data-layout-value', value);
      btn.textContent = label;
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        layout = value;
        layoutGroup.querySelectorAll('.mcp-dir-btn').forEach((b) => {
          b.classList.toggle('mcp-dir-btn--active', b === btn);
        });
        await renderDiagram();
      });
      layoutGroup.appendChild(btn);
    });
    layoutRow.appendChild(layoutGroup);
    panel.appendChild(layoutRow);

    // Direction
    const dirRow = makeRow();
    dirRow.classList.add('mcp-row--direction');
    dirRow.appendChild(makeLabel('Direction'));
    const dirGroup = document.createElement('div');
    dirGroup.className = 'mcp-dir-group';
    [
      { value: 'TB', glyph: '\u2193', title: 'Top → bottom' },
      { value: 'BT', glyph: '\u2191', title: 'Bottom → top' },
      { value: 'LR', glyph: '\u2192', title: 'Left → right' },
      { value: 'RL', glyph: '\u2190', title: 'Right → left' },
    ].forEach(({ value, glyph, title: tip }) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `mcp-dir-btn${direction === value ? ' mcp-dir-btn--active' : ''}`;
      btn.setAttribute('data-dir-value', value);
      btn.textContent = glyph;
      btn.title = tip;
      btn.setAttribute('aria-label', tip);
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        direction = value;
        dirGroup.querySelectorAll('.mcp-dir-btn').forEach((b) => {
          b.classList.toggle('mcp-dir-btn--active', b === btn);
        });
        await renderDiagram();
      });
      dirGroup.appendChild(btn);
    });
    dirRow.appendChild(dirGroup);
    panel.appendChild(dirRow);

    // Zoom
    const zoomRow = makeRow();
    zoomRow.appendChild(makeLabel('Zoom'));
    const zoomGroup = document.createElement('div');
    zoomGroup.className = 'mcp-zoom-group';

    const zoomOutBtn = document.createElement('button');
    zoomOutBtn.type = 'button';
    zoomOutBtn.className = 'mcp-zoom-btn';
    zoomOutBtn.setAttribute('aria-label', 'Zoom out');
    zoomOutBtn.textContent = '\u2212';

    const zoomInput = document.createElement('input');
    zoomInput.className = 'mcp-zoom-readout';
    zoomInput.type = 'text';
    zoomInput.inputMode = 'decimal';
    zoomInput.setAttribute('aria-label', 'Zoom percent');

    const zoomInBtn = document.createElement('button');
    zoomInBtn.type = 'button';
    zoomInBtn.className = 'mcp-zoom-btn';
    zoomInBtn.setAttribute('aria-label', 'Zoom in');
    zoomInBtn.textContent = '+';

    const syncZoomInput = () => {
      zoomInput.value = String(Math.round(zoomLevel * 100));
      zoomOutBtn.disabled = zoomLevel <= ZOOM_MIN + 1e-9;
      zoomInBtn.disabled = zoomLevel >= ZOOM_MAX - 1e-9;
      zoomPresetsWrap.querySelectorAll('.mcp-zoom-preset-btn').forEach((btn) => {
        const pct = Number(btn.getAttribute('data-zoom-preset'));
        const active = Math.abs(zoomLevel - pct / 100) < 1e-9;
        btn.classList.toggle('mcp-zoom-preset-btn--active', active);
      });
    };

    applyZoom = () => {
      const svg = zoomHost.querySelector('svg');
      if (!svg) return;
      const { w: bw, h: bh } = readSvgViewBoxSize(svg);
      const pw = bw * zoomLevel;
      const ph = bh * zoomLevel;
      svg.setAttribute('width', String(pw));
      svg.setAttribute('height', String(ph));
      svg.style.width = `${pw}px`;
      svg.style.height = `${ph}px`;
      svg.style.maxWidth = 'none';
      zoomHost.style.transform = `translate(${Math.round(panOffset.x)}px, ${Math.round(panOffset.y)}px)`;
      syncZoomInput();
    };

    const bumpZoom = (delta) => {
      const stepped = Math.round((zoomLevel + delta) / ZOOM_STEP) * ZOOM_STEP;
      zoomLevel = clampZoom(stepped);
      applyZoom();
    };

    zoomOutBtn.addEventListener('click', (e) => { e.stopPropagation(); bumpZoom(-ZOOM_STEP); });
    zoomInBtn.addEventListener('click', (e) => { e.stopPropagation(); bumpZoom(ZOOM_STEP); });
    zoomInput.addEventListener('click', (e) => e.stopPropagation());
    zoomInput.addEventListener('keydown', (e) => {
      e.stopPropagation();
      if (e.key === 'Enter') {
        e.preventDefault();
        const pct = parseFloat(String(zoomInput.value).replace(/%/g, ''));
        if (Number.isFinite(pct)) {
          zoomLevel = clampZoom(pct / 100);
          applyZoom();
        } else {
          syncZoomInput();
        }
        zoomInput.blur();
      }
    });
    zoomInput.addEventListener('blur', () => {
      const pct = parseFloat(String(zoomInput.value).replace(/%/g, ''));
      if (Number.isFinite(pct)) {
        zoomLevel = clampZoom(pct / 100);
        applyZoom();
      } else {
        syncZoomInput();
      }
    });

    zoomGroup.appendChild(zoomOutBtn);
    zoomGroup.appendChild(zoomInput);
    zoomGroup.appendChild(zoomInBtn);
    zoomRow.appendChild(zoomGroup);
    panel.appendChild(zoomRow);

    const zoomPresetsWrap = document.createElement('div');
    zoomPresetsWrap.className = 'mcp-zoom-presets';
    ZOOM_PRESETS.forEach((pct) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mcp-zoom-preset-btn';
      btn.setAttribute('data-zoom-preset', String(pct));
      btn.textContent = `${pct}%`;
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        zoomLevel = clampZoom(pct / 100);
        applyZoom();
      });
      zoomPresetsWrap.appendChild(btn);
    });
    panel.appendChild(zoomPresetsWrap);

    const fitToScreen = () => {
      const svg = zoomHost.querySelector('svg');
      if (!svg) return;
      const { w: bw, h: bh } = readSvgViewBoxSize(svg);
      const cw = fsDiagramView.clientWidth - 48;
      const ch = fsDiagramView.clientHeight - 48;
      if (!bw || !bh || !cw || !ch) return;
      zoomLevel = clampZoom(Math.min(cw / bw, ch / bh) * 0.96);
      panOffset = { x: 0, y: 0 };
      applyZoom();
    };

    const fitToWidth = () => {
      const svg = zoomHost.querySelector('svg');
      if (!svg) return;
      const { w: bw } = readSvgViewBoxSize(svg);
      const cw = fsDiagramView.clientWidth - 48;
      if (!bw || !cw) return;
      zoomLevel = clampZoom((cw / bw) * 0.98);
      panOffset = { x: 0, y: 0 };
      applyZoom();
    };

    const fitScreenBtn = document.createElement('button');
    fitScreenBtn.type = 'button';
    fitScreenBtn.className = 'mcp-btn';
    fitScreenBtn.textContent = 'Fit to screen';
    fitScreenBtn.addEventListener('click', (e) => { e.stopPropagation(); fitToScreen(); });
    panel.appendChild(fitScreenBtn);

    const fitWidthBtn = document.createElement('button');
    fitWidthBtn.type = 'button';
    fitWidthBtn.className = 'mcp-btn';
    fitWidthBtn.textContent = 'Fit to width';
    fitWidthBtn.addEventListener('click', (e) => { e.stopPropagation(); fitToWidth(); });
    panel.appendChild(fitWidthBtn);

    // Pan mode
    const panBtn = document.createElement('button');
    panBtn.type = 'button';
    panBtn.className = 'mcp-btn';
    const syncPanBtn = () => {
      panBtn.textContent = panModeEnabled ? 'Pan Mode: ON' : 'Pan Mode: OFF';
      panBtn.classList.toggle('mcp-btn--active', panModeEnabled);
      fsDiagramView.classList.toggle('mermaid-pan-enabled', panModeEnabled);
    };
    syncPanBtn();
    panBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      panModeEnabled = !panModeEnabled;
      if (!panModeEnabled) isPanning = false;
      syncPanBtn();
    });
    panel.appendChild(panBtn);

    let startX = 0;
    let startY = 0;
    let startPan = { x: 0, y: 0 };
    const onPointerMovePan = (e) => {
      if (!isPanning) return;
      panOffset = {
        x: startPan.x + (e.clientX - startX),
        y: startPan.y + (e.clientY - startY),
      };
      applyZoom();
    };
    const stopPanning = () => {
      isPanning = false;
      fsDiagramView.classList.remove('mermaid-panning');
      window.removeEventListener('pointermove', onPointerMovePan);
      window.removeEventListener('pointerup', stopPanning);
      window.removeEventListener('pointercancel', stopPanning);
    };
    fsDiagramView.addEventListener('pointerdown', (e) => {
      if (!panModeEnabled || e.button !== 0) return;
      if (e.target instanceof HTMLElement && e.target.closest('button, a, input, select, .mermaid-controls')) return;
      isPanning = true;
      startX = e.clientX;
      startY = e.clientY;
      startPan = { ...panOffset };
      fsDiagramView.classList.add('mermaid-panning');
      window.addEventListener('pointermove', onPointerMovePan);
      window.addEventListener('pointerup', stopPanning);
      window.addEventListener('pointercancel', stopPanning);
      e.preventDefault();
    });

    fsDiagramView.addEventListener('wheel', (e) => {
      if (!e.ctrlKey) return;
      e.preventDefault();
      if (showingCode) return;
      bumpZoom(e.deltaY > 0 ? -ZOOM_STEP : ZOOM_STEP);
    }, { passive: false });

    // View / copy code
    const codeBtn = document.createElement('button');
    codeBtn.type = 'button';
    codeBtn.className = 'mcp-btn';
    codeBtn.innerHTML = '&#9670;&nbsp; View Code';
    const applyViewMode = () => {
      if (showingCode) {
        fsDiagramView.style.visibility = 'hidden';
        fsCodeView.hidden = false;
        codeBtn.innerHTML = '&#9670;&nbsp; View Diagram';
        codeBtn.classList.add('mcp-btn--active');
      } else {
        fsDiagramView.style.visibility = '';
        fsCodeView.hidden = true;
        codeBtn.innerHTML = '&#9670;&nbsp; View Code';
        codeBtn.classList.remove('mcp-btn--active');
      }
    };
    codeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      showingCode = !showingCode;
      applyViewMode();
    });
    panel.appendChild(codeBtn);

    const copyBtn = document.createElement('button');
    copyBtn.type = 'button';
    copyBtn.className = 'mcp-btn';
    copyBtn.textContent = 'Copy Code';
    copyBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      copyText(fsCodeView.textContent || baseCode, copyBtn);
    });
    panel.appendChild(copyBtn);

    const liveBtn = document.createElement('button');
    liveBtn.type = 'button';
    liveBtn.className = 'mcp-btn';
    liveBtn.innerHTML = '&#10024;&nbsp; Mermaid Live';
    liveBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      openMermaidLive(fsCodeView.textContent || applyMermaidOptions(baseCode, layout, direction));
    });
    panel.appendChild(liveBtn);

    // Panel open/close
    let panelOpen = false;
    const openPanel = () => {
      panel.hidden = false;
      panelOpen = true;
      trigger.setAttribute('aria-expanded', 'true');
      trigger.classList.add('mermaid-controls__trigger--active');
      panel.classList.remove('mermaid-controls__panel--leave');
      panel.classList.add('mermaid-controls__panel--enter');
    };
    const closePanel = () => {
      panelOpen = false;
      trigger.setAttribute('aria-expanded', 'false');
      trigger.classList.remove('mermaid-controls__trigger--active');
      panel.classList.remove('mermaid-controls__panel--enter');
      panel.classList.add('mermaid-controls__panel--leave');
      window.setTimeout(() => {
        if (!panelOpen) {
          panel.hidden = true;
          panel.classList.remove('mermaid-controls__panel--leave');
        }
      }, 280);
    };
    trigger.addEventListener('click', (e) => {
      e.stopPropagation();
      if (panelOpen) closePanel();
      else openPanel();
    });
    panel.addEventListener('click', (e) => e.stopPropagation());

    controls.appendChild(trigger);
    controls.appendChild(panel);

    // Exit
    const exitBtn = document.createElement('button');
    exitBtn.type = 'button';
    exitBtn.className = 'mermaid-fs-exit';
    exitBtn.setAttribute('aria-label', 'Exit fullscreen');
    exitBtn.innerHTML = '&#10005; Exit';

    fsEl.appendChild(fsDiagramView);
    fsEl.appendChild(fsCodeView);
    fsEl.appendChild(controls);
    fsEl.appendChild(exitBtn);
    document.body.appendChild(fsEl);

    const removeFsEl = () => {
      if (fsEl.parentNode) fsEl.parentNode.removeChild(fsEl);
    };

    const exitFs = () => {
      removeFsEl();
      const exitFn = document.exitFullscreen
        || document.webkitExitFullscreen
        || document.mozCancelFullScreen
        || document.msExitFullscreen;
      if (exitFn && (document.fullscreenElement || document.webkitFullscreenElement)) {
        exitFn.call(document).catch(() => {});
      }
      document.removeEventListener('fullscreenchange', onFsChange);
      document.removeEventListener('webkitfullscreenchange', onFsChange);
      document.removeEventListener('keydown', onKeyDown);
    };

    const onFsChange = () => {
      if (!document.fullscreenElement && !document.webkitFullscreenElement) {
        removeFsEl();
        document.removeEventListener('fullscreenchange', onFsChange);
        document.removeEventListener('webkitfullscreenchange', onFsChange);
        document.removeEventListener('keydown', onKeyDown);
      }
    };

    const onKeyDown = (e) => {
      if (e.key === 'Escape') exitFs();
    };

    exitBtn.addEventListener('click', exitFs);
    document.addEventListener('fullscreenchange', onFsChange);
    document.addEventListener('webkitfullscreenchange', onFsChange);
    document.addEventListener('keydown', onKeyDown);

    try {
      await renderDiagram();
      requestAnimationFrame(() => {
        requestAnimationFrame(() => fitToScreen());
      });
    } catch (err) {
      zoomHost.innerHTML = '<div class="mermaid-error" role="alert">Diagram could not render.</div>';
      console.error('[template-mermaid] fullscreen render failed', err);
    }

    const requestFn = fsEl.requestFullscreen
      || fsEl.webkitRequestFullscreen
      || fsEl.mozRequestFullScreen
      || fsEl.msRequestFullscreen;
    if (requestFn) {
      requestFn.call(fsEl).catch(() => {});
    }
  };

  window.TemplateMermaidViewer = {
    openFullscreen,
    applyMermaidOptions,
    openMermaidLive,
    patchMermaidFlowchartLabels,
    schedulePatchMermaidLabels,
  };
})();
