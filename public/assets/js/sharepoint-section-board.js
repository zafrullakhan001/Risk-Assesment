/**
 * SharePoint catalog page: section rearrange (stack layout).
 * Cards layout is disabled for now; order persists in localStorage.
 */
(function () {
  'use strict';

  const LAYOUT_KEY = 'ra-sp-section-layout';
  const ORDER_KEY = 'ra-sp-section-order';
  const DEFAULT_ORDER = [
    'folders',
    'catalog-share',
    'owners',
    'owners-share',
    'search',
    'projects',
    'admin',
  ];

  const board = document.getElementById('sharepoint-section-board');
  if (!board) return;

  const toolbar = board.querySelector('.sharepoint-section-board-toolbar');
  const resetBtn = document.getElementById('sharepoint-section-reset');

  function sections() {
    return Array.from(board.querySelectorAll(':scope > [data-sp-section]'));
  }

  function sectionKeys() {
    return sections().map((el) => el.getAttribute('data-sp-section') || '').filter(Boolean);
  }

  function readOrder() {
    try {
      const raw = JSON.parse(localStorage.getItem(ORDER_KEY) || 'null');
      if (!Array.isArray(raw)) return null;
      return raw.map(String).filter(Boolean);
    } catch (e) {
      return null;
    }
  }

  function writeOrder(keys) {
    try {
      localStorage.setItem(ORDER_KEY, JSON.stringify(keys));
    } catch (e) {
      /* ignore */
    }
  }

  function clearOrder() {
    try {
      localStorage.removeItem(ORDER_KEY);
    } catch (e) {
      /* ignore */
    }
  }

  /** Cards view is parked; always use stack. */
  function forceStackLayout() {
    board.setAttribute('data-layout', 'stack');
    board.classList.remove('is-single-col');
    try {
      localStorage.setItem(LAYOUT_KEY, 'stack');
    } catch (e) {
      /* ignore */
    }
  }

  function applyOrder(preferredKeys) {
    const byKey = new Map();
    sections().forEach((el) => {
      const key = el.getAttribute('data-sp-section');
      if (key) byKey.set(key, el);
    });

    const ordered = [];
    const seen = new Set();
    (preferredKeys || []).forEach((key) => {
      const el = byKey.get(key);
      if (el && !seen.has(key)) {
        ordered.push(el);
        seen.add(key);
      }
    });
    sections().forEach((el) => {
      const key = el.getAttribute('data-sp-section');
      if (key && !seen.has(key)) {
        ordered.push(el);
        seen.add(key);
      }
    });

    let insertAfter = toolbar;
    ordered.forEach((el) => {
      if (insertAfter) {
        insertAfter.insertAdjacentElement('afterend', el);
      } else {
        board.prepend(el);
      }
      insertAfter = el;
    });

    updateMoveButtons();
  }

  function persistCurrentOrder() {
    writeOrder(sectionKeys());
  }

  function swapAt(index, targetIndex) {
    const list = sections();
    if (index < 0 || targetIndex < 0 || index >= list.length || targetIndex >= list.length) {
      return;
    }
    if (index === targetIndex) return;

    const a = list[index];
    const b = list[targetIndex];
    if (index < targetIndex) {
      b.insertAdjacentElement('afterend', a);
    } else {
      b.insertAdjacentElement('beforebegin', a);
    }
    persistCurrentOrder();
    updateMoveButtons();
  }

  function moveSection(section, direction) {
    const list = sections();
    const index = list.indexOf(section);
    if (index < 0) return;

    let target = -1;
    if (direction === 'up') {
      target = index - 1;
    } else if (direction === 'down') {
      target = index + 1;
    } else {
      // left/right reserved for a future cards layout
      return;
    }

    if (target < 0 || target >= list.length) return;
    swapAt(index, target);
  }

  function updateMoveButtons() {
    const list = sections();
    list.forEach((section, index) => {
      const up = section.querySelector('[data-sp-move="up"]');
      const down = section.querySelector('[data-sp-move="down"]');
      if (up) up.disabled = index === 0;
      if (down) down.disabled = index === list.length - 1;
    });
  }

  function insertBeforeSection(dragged, target) {
    if (!dragged || !target || dragged === target) return;
    target.insertAdjacentElement('beforebegin', dragged);
    persistCurrentOrder();
    updateMoveButtons();
  }

  resetBtn?.addEventListener('click', () => {
    clearOrder();
    applyOrder(DEFAULT_ORDER);
    forceStackLayout();
  });

  // Capture: tools wrappers use stopPropagation to avoid toggling <details>
  board.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-sp-move]');
    if (!btn || !board.contains(btn)) return;
    event.preventDefault();
    event.stopPropagation();
    const section = btn.closest('[data-sp-section]');
    if (!section) return;
    moveSection(section, btn.getAttribute('data-sp-move') || '');
  }, true);

  let dragSection = null;

  function canStartDrag(event) {
    if (event.target.closest('button, a, input, select, textarea, label, [data-no-toggle]')) {
      return false;
    }
    const summary = event.target.closest('summary');
    return Boolean(summary && summary.closest('[data-sp-section]'));
  }

  board.addEventListener('mousedown', (event) => {
    if (!canStartDrag(event)) return;
    const section = event.target.closest('[data-sp-section]');
    if (!section || !board.contains(section)) return;
    section.setAttribute('draggable', 'true');
  });

  board.addEventListener('dragstart', (event) => {
    const section = event.target.closest('[data-sp-section]');
    if (!section || section.getAttribute('draggable') !== 'true') {
      event.preventDefault();
      return;
    }
    if (!canStartDrag(event) && event.target !== section) {
      if (!event.target.closest('summary')) {
        event.preventDefault();
        return;
      }
    }
    dragSection = section;
    section.classList.add('is-sp-dragging');
    try {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', section.getAttribute('data-sp-section') || '');
    } catch (e) {
      /* ignore */
    }
  });

  board.addEventListener('dragend', () => {
    sections().forEach((el) => {
      el.classList.remove('is-sp-dragging', 'is-sp-drop-target');
      el.removeAttribute('draggable');
    });
    dragSection = null;
  });

  board.addEventListener('dragover', (event) => {
    if (!dragSection) return;
    const target = event.target.closest('[data-sp-section]');
    if (!target || target === dragSection) return;
    event.preventDefault();
    try {
      event.dataTransfer.dropEffect = 'move';
    } catch (e) {
      /* ignore */
    }
    sections().forEach((el) => el.classList.toggle('is-sp-drop-target', el === target));
  });

  board.addEventListener('dragleave', (event) => {
    const target = event.target.closest('[data-sp-section]');
    if (target) target.classList.remove('is-sp-drop-target');
  });

  board.addEventListener('drop', (event) => {
    if (!dragSection) return;
    const target = event.target.closest('[data-sp-section]');
    if (!target || target === dragSection) return;
    event.preventDefault();
    insertBeforeSection(dragSection, target);
    sections().forEach((el) => el.classList.remove('is-sp-drop-target', 'is-sp-dragging'));
    dragSection.removeAttribute('draggable');
    dragSection = null;
  });

  const savedOrder = readOrder();
  if (savedOrder && savedOrder.length) {
    applyOrder(savedOrder);
  } else {
    updateMoveButtons();
  }
  forceStackLayout();
})();
