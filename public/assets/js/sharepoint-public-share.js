(() => {
  const SCROLL_KEY = 'riskregister_sp_share_scroll';
  const PANEL_KEY = 'riskregister_sp_share_panel';
  const OFFSET_KEY = 'riskregister_sp_share_panel_offset';

  const copyText = async (input) => {
    const value = String(input?.value || '');
    if (!value) return false;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(value);
      return true;
    }
    input.select();
    document.execCommand('copy');
    return true;
  };

  document.querySelectorAll('.share-link-copy-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const input = document.getElementById(btn.getAttribute('data-copy-input') || '');
      const statusEl = document.getElementById(btn.getAttribute('data-copy-status') || '');
      if (!input) return;
      try {
        await copyText(input);
        if (statusEl) {
          statusEl.hidden = false;
          statusEl.textContent = 'Link copied to clipboard.';
        }
      } catch {
        input.select();
        if (statusEl) {
          statusEl.hidden = false;
          statusEl.textContent = 'Select the link and press Ctrl+C to copy.';
        }
      }
    });
  });

  try {
    if ('scrollRestoration' in history) {
      history.scrollRestoration = 'manual';
    }
  } catch {
    /* ignore */
  }

  const readSavedScroll = () => {
    try {
      const rawY = sessionStorage.getItem(SCROLL_KEY);
      const panelId = sessionStorage.getItem(PANEL_KEY) || '';
      const rawOffset = sessionStorage.getItem(OFFSET_KEY);
      if (rawY === null || rawY === '') {
        return null;
      }
      const y = Number.parseInt(rawY, 10);
      if (!Number.isFinite(y) || y < 0) {
        return null;
      }
      const offset = rawOffset === null || rawOffset === ''
        ? null
        : Number.parseInt(rawOffset, 10);
      return {
        y,
        panelId,
        offset: Number.isFinite(offset) ? offset : null,
      };
    } catch {
      return null;
    }
  };

  const clearSavedScroll = () => {
    try {
      sessionStorage.removeItem(SCROLL_KEY);
      sessionStorage.removeItem(PANEL_KEY);
      sessionStorage.removeItem(OFFSET_KEY);
    } catch {
      /* ignore */
    }
  };

  const saveScrollForCard = (card) => {
    try {
      sessionStorage.setItem(SCROLL_KEY, String(Math.max(0, Math.round(window.scrollY))));
      if (card?.id) {
        sessionStorage.setItem(PANEL_KEY, card.id);
        sessionStorage.setItem(
          OFFSET_KEY,
          String(Math.round(card.getBoundingClientRect().top))
        );
      } else {
        sessionStorage.removeItem(PANEL_KEY);
        sessionStorage.removeItem(OFFSET_KEY);
      }
    } catch {
      /* ignore */
    }
  };

  const restoreScrollY = (y) => {
    const top = Math.max(0, Math.round(y));
    const se = document.scrollingElement || document.documentElement;
    if (se) {
      se.scrollTop = top;
    }
    try {
      window.scrollTo({ top, left: 0, behavior: 'instant' });
    } catch {
      window.scrollTo(0, top);
    }
  };

  const resolvePinnedScrollY = (saved) => {
    if (!saved) return 0;
    if (saved.panelId && saved.offset !== null) {
      const card = document.getElementById(saved.panelId);
      if (card) {
        return Math.max(
          0,
          Math.round(card.getBoundingClientRect().top + window.scrollY - saved.offset)
        );
      }
    }
    return saved.y;
  };

  /** Keep the share card pinned in the viewport while layout settles. */
  const lockScroll = (saved) => {
    const apply = () => restoreScrollY(resolvePinnedScrollY(saved));
    apply();
    [0, 16, 50, 120, 250, 400, 700].forEach((ms) => {
      window.setTimeout(apply, ms);
    });
    requestAnimationFrame(() => {
      apply();
      requestAnimationFrame(apply);
    });
  };

  const openShellContext = (card) => {
    if (!(card instanceof HTMLElement)) return;
    const shell = card.querySelector('.sharepoint-share-shell');
    if (shell) shell.open = true;
    if (/[?&]emailed=/.test(window.location.search)) {
      const emailPanel = card.querySelector('.share-subpanel-email');
      if (emailPanel) emailPanel.open = true;
    }
    if (
      /[?&](catalog_shared|owners_shared)=/.test(window.location.search)
      && !card.querySelector('.share-link-fresh')
    ) {
      card.querySelectorAll('.share-subpanel').forEach((panel) => {
        const title = panel.querySelector('.share-subpanel-title')?.textContent || '';
        if (/link history/i.test(title)) {
          panel.open = true;
        }
      });
    }
  };

  const stripShareHash = () => {
    const hash = window.location.hash;
    if (hash !== '#catalog-share-panel' && hash !== '#owners-share-panel') {
      return;
    }
    try {
      const url = new URL(window.location.href);
      url.hash = '';
      window.history.replaceState(window.history.state, '', url.pathname + url.search);
    } catch {
      /* ignore */
    }
  };

  // Capture scroll before any share POST leaves the page.
  document.querySelectorAll('.sharepoint-share-card').forEach((card) => {
    card.querySelectorAll('form').forEach((form) => {
      form.addEventListener('submit', (event) => {
        const submitter = event.submitter;
        const confirmMsg =
          (submitter && submitter.getAttribute('data-share-confirm'))
          || form.getAttribute('data-share-confirm')
          || '';
        if (confirmMsg !== '' && !window.confirm(confirmMsg)) {
          event.preventDefault();
          event.stopPropagation();
          return;
        }
        saveScrollForCard(card);
      });
    });
  });

  // History pagination links also reload — keep viewport stable.
  document.querySelectorAll('.share-link-history-pager a').forEach((link) => {
    link.addEventListener('click', () => {
      const card = link.closest('.sharepoint-share-card');
      saveScrollForCard(card);
    });
  });

  const saved = readSavedScroll();
  const queryAnchorsShare =
    /[?&](catalog_shared|owners_shared|emailed)=/.test(window.location.search);
  const queryHistoryPage =
    /[?&](cshare_page|oshare_page)=/.test(window.location.search);
  const hashId = window.location.hash;
  const hashCard =
    (hashId === '#catalog-share-panel' || hashId === '#owners-share-panel')
      ? document.getElementById(hashId.slice(1))
      : null;
  const hasShareFlash = document.querySelector(
    '.sharepoint-share-card .share-panel-flash, .sharepoint-share-card .share-link-fresh'
  ) !== null;
  const shouldRestoreScroll =
    saved !== null
    && (queryAnchorsShare || queryHistoryPage || hasShareFlash || hashCard !== null);

  document.querySelectorAll('.sharepoint-share-card').forEach((card) => {
    const shell = card.querySelector('.sharepoint-share-shell');
    if (!shell) return;
    const kind = card.getAttribute('data-share-kind') || 'catalog';
    const storageKey = `riskregister_sp_share_shell_${kind}`;
    const forced = shell.hasAttribute('open');
    const shouldKeepOpen =
      forced
      || (saved && saved.panelId === card.id)
      || (hashCard && hashCard === card)
      || card.querySelector('.share-panel-flash, .share-link-fresh') !== null
      || (kind === 'catalog' && /[?&](catalog_shared|emailed)=/.test(window.location.search))
      || (kind === 'owners' && /[?&](owners_shared|emailed)=/.test(window.location.search));

    card.querySelectorAll('[data-no-toggle]').forEach((el) => {
      el.addEventListener('click', (event) => event.stopPropagation());
      el.addEventListener('pointerdown', (event) => event.stopPropagation());
    });

    if (shouldKeepOpen) {
      shell.open = true;
      openShellContext(card);
    } else if (!forced) {
      try {
        const stored = localStorage.getItem(storageKey);
        if (stored === '1') shell.open = true;
        else if (stored === '0') shell.open = false;
      } catch {
        /* ignore */
      }
    }

    shell.addEventListener('toggle', () => {
      try {
        localStorage.setItem(storageKey, shell.open ? '1' : '0');
      } catch {
        /* ignore */
      }
    });
  });

  if (shouldRestoreScroll && saved) {
    stripShareHash();
    lockScroll(saved);
    clearSavedScroll();
  } else {
    if (saved) {
      clearSavedScroll();
    }
    if (queryAnchorsShare || hashCard) {
      const target =
        hashCard
        || document.getElementById(
          /[?&]owners_shared=/.test(window.location.search)
            ? 'owners-share-panel'
            : 'catalog-share-panel'
        );
      if (target) {
        stripShareHash();
        lockScroll({
          y: Math.max(0, Math.round(target.getBoundingClientRect().top + window.scrollY - 12)),
          panelId: target.id,
          offset: 12,
        });
      }
    }
  }
})();
