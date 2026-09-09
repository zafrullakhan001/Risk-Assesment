(() => {
  'use strict';

  const STORAGE_KEY = 'riskregister_sp_file_list_animation';
  const DEFAULT_ANIMATION = 'quiet-settle';
  const OPTIONS = [
    { id: 'none', name: 'None', description: 'No entrance animation', icon: '⭕' },
    { id: 'fade-in', name: 'Fade In', description: 'Gentle opacity reveal', icon: '✨' },
    { id: 'slide-up', name: 'Slide Up', description: 'Rise smoothly from below', icon: '⬆️' },
    { id: 'slide-down', name: 'Slide Down', description: 'Settle smoothly from above', icon: '⬇️' },
    { id: 'slide-left', name: 'Slide Left', description: 'Enter from the right', icon: '⬅️' },
    { id: 'slide-right', name: 'Slide Right', description: 'Enter from the left', icon: '➡️' },
    { id: 'zoom-in', name: 'Zoom In', description: 'Soft scale-up entrance', icon: '🔍' },
    { id: 'zoom-out', name: 'Zoom Out', description: 'Soft scale-down entrance', icon: '🔎' },
    { id: 'blur-in', name: 'Blur In', description: 'Focus from a soft blur', icon: '🌫️' },
    { id: 'reveal', name: 'Reveal', description: 'Clean center wipe', icon: '🎭' },
    { id: 'flip-x', name: 'Flip Horizontal', description: 'Subtle horizontal turn', icon: '🔄' },
    { id: 'flip-y', name: 'Flip Vertical', description: 'Subtle vertical turn', icon: '🔃' },
    { id: 'perspective-in', name: 'Perspective', description: 'Professional depth entrance', icon: '🖼️' },
    { id: 'document-slide', name: 'Document Slide', description: 'Paper-like side entrance', icon: '📄' },
    { id: 'soft-landing', name: 'Soft Landing', description: 'Controlled drop and settle', icon: '🪂' },
    { id: 'quiet-settle', name: 'Quiet Settle', description: 'Calm staggered movement', icon: '🤫' },
  ];
  const optionIds = new Set(OPTIONS.map((option) => option.id));

  const dialog = document.getElementById('sharepoint-list-animation-dialog');
  const openButton = document.getElementById('sharepoint-list-animation-open');
  const optionsRoot = document.getElementById('sharepoint-list-animation-options');
  const searchInput = document.getElementById('sharepoint-list-animation-search');
  const previewCard = document.getElementById('sharepoint-list-animation-preview-card');
  const previewName = document.getElementById('sharepoint-list-animation-preview-name');
  const currentName = document.getElementById('sharepoint-list-animation-current');
  const footerName = document.getElementById('sharepoint-list-animation-footer-name');
  const emptyState = document.getElementById('sharepoint-list-animation-empty');
  const replayButton = document.getElementById('sharepoint-list-animation-replay');
  const resultCard = document.getElementById('sharepoint-table-card');
  const workspaceDialogs = [
    ...document.querySelectorAll('.sharepoint-project-dialog, .sharepoint-compare-dialog'),
  ];
  const dialogRows = [
    document.getElementById('sharepoint-project-dialog-rows'),
    document.getElementById('sharepoint-compare-left-rows'),
    document.getElementById('sharepoint-compare-mid-rows'),
    document.getElementById('sharepoint-compare-right-rows'),
  ].filter(Boolean);
  if (!dialog || !openButton || !optionsRoot || !resultCard) return;

  const readSelection = () => {
    try {
      const saved = localStorage.getItem(STORAGE_KEY);
      return optionIds.has(saved) ? saved : DEFAULT_ANIMATION;
    } catch {
      return DEFAULT_ANIMATION;
    }
  };

  let selected = readSelection();

  const selectedOption = () => OPTIONS.find((option) => option.id === selected) || OPTIONS[0];

  const replayDialogRows = (tbody) => {
    const ownerDialog = tbody?.closest('.sharepoint-project-dialog, .sharepoint-compare-dialog');
    if (!tbody || !ownerDialog?.open || selected === 'none') return;
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches) return;

    const rows = [...tbody.querySelectorAll('.sp-dialog-row')].slice(0, 24);
    rows.forEach((row) => {
      row.classList.remove('is-settling');
      row.style.removeProperty('--sp-settle-delay');
    });
    void tbody.offsetWidth;
    rows.forEach((row, index) => {
      row.classList.add('is-settling');
      row.style.setProperty('--sp-settle-delay', `${Math.min(index * 18, 180)}ms`);
    });
  };

  const applySelection = ({ persist = false, replayList = false } = {}) => {
    const option = selectedOption();
    resultCard.dataset.listAnimation = option.id;
    workspaceDialogs.forEach((workspaceDialog) => {
      workspaceDialog.dataset.listAnimation = option.id;
    });
    if (currentName) currentName.textContent = option.name;
    if (previewName) previewName.textContent = option.name;
    if (footerName) footerName.textContent = option.name;

    if (persist) {
      try {
        localStorage.setItem(STORAGE_KEY, option.id);
      } catch {
        // Browser storage may be disabled; the in-page selection still works.
      }
    }

    if (replayList && typeof window.RiskRegisterSharePoint?.replayListAnimation === 'function') {
      window.RiskRegisterSharePoint.replayListAnimation();
    }
    if (replayList) {
      dialogRows.forEach(replayDialogRows);
    }
  };

  const replayPreview = () => {
    if (!previewCard) return;
    previewCard.removeAttribute('data-preview-animation');
    void previewCard.offsetWidth;
    previewCard.setAttribute('data-preview-animation', selected);
  };

  const makeOptionButton = (option, index) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'sp-list-animation-option';
    button.setAttribute('role', 'option');
    button.setAttribute('data-animation-id', option.id);
    button.setAttribute('aria-selected', option.id === selected ? 'true' : 'false');

    const rank = document.createElement('span');
    rank.className = 'sp-list-animation-rank';
    rank.textContent = `#${index + 1}`;
    const icon = document.createElement('span');
    icon.className = 'sp-list-animation-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = option.icon;
    const copy = document.createElement('span');
    copy.className = 'sp-list-animation-option-copy';
    const name = document.createElement('strong');
    name.textContent = option.name;
    const description = document.createElement('small');
    description.textContent = option.description;
    copy.append(name, description);
    button.append(rank, icon, copy);

    button.addEventListener('click', () => {
      selected = option.id;
      applySelection({ persist: true, replayList: true });
      renderOptions(searchInput?.value || '');
      replayPreview();
    });
    return button;
  };

  const renderOptions = (query = '') => {
    const needle = String(query).trim().toLowerCase();
    const visible = OPTIONS.filter((option) =>
      `${option.name} ${option.description}`.toLowerCase().includes(needle)
    );
    optionsRoot.replaceChildren(
      ...visible.map((option) => makeOptionButton(option, OPTIONS.indexOf(option)))
    );
    if (emptyState) emptyState.hidden = visible.length !== 0;
  };

  const openDialog = () => {
    renderOptions(searchInput?.value || '');
    replayPreview();
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
    window.setTimeout(() => searchInput?.focus(), 0);
  };

  const closeDialog = () => {
    if (typeof dialog.close === 'function' && dialog.open) dialog.close();
    else dialog.removeAttribute('open');
    openButton.focus();
  };

  openButton.addEventListener('click', openDialog);
  dialog.querySelectorAll('[data-animation-close]').forEach((button) => {
    button.addEventListener('click', closeDialog);
  });
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) closeDialog();
  });
  searchInput?.addEventListener('input', () => renderOptions(searchInput.value));
  replayButton?.addEventListener('click', () => {
    replayPreview();
    window.RiskRegisterSharePoint?.replayListAnimation?.();
  });
  window.addEventListener('storage', (event) => {
    if (event.key !== STORAGE_KEY) return;
    selected = readSelection();
    applySelection();
    renderOptions(searchInput?.value || '');
  });

  dialogRows.forEach((tbody) => {
    const observer = new MutationObserver((mutations) => {
      if (!mutations.some((mutation) => mutation.type === 'childList')) return;
      window.requestAnimationFrame(() => replayDialogRows(tbody));
    });
    observer.observe(tbody, { childList: true });
  });

  applySelection();
  renderOptions();
})();
