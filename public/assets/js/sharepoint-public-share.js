(() => {
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

  const scrollShareCardIntoView = (card) => {
    if (!(card instanceof HTMLElement)) return;
    const run = () => {
      card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    requestAnimationFrame(() => {
      requestAnimationFrame(run);
    });
    window.setTimeout(run, 80);
  };

  const focusShareTarget = (card) => {
    if (!(card instanceof HTMLElement)) return;
    const shell = card.querySelector('.sharepoint-share-shell');
    if (shell) shell.open = true;
    const fresh = card.querySelector('.share-link-fresh, .share-panel-flash');
    const emailPanel = card.querySelector('.share-subpanel-email');
    if (fresh) {
      /* keep create/email context visible after create or email */
    } else if (emailPanel && window.location.search.includes('emailed=')) {
      emailPanel.open = true;
    }
    scrollShareCardIntoView(card);
  };

  try {
    if ('scrollRestoration' in history) {
      history.scrollRestoration = 'manual';
    }
  } catch {
    /* ignore */
  }

  document.querySelectorAll('.sharepoint-share-card').forEach((card) => {
    const shell = card.querySelector('.sharepoint-share-shell');
    if (!shell) return;
    const kind = card.getAttribute('data-share-kind') || 'catalog';
    const storageKey = `riskregister_sp_share_shell_${kind}`;
    const hashId = card.id ? `#${card.id}` : '';
    const forced = shell.hasAttribute('open');
    const shouldAnchor =
      (hashId && window.location.hash === hashId) ||
      card.querySelector('.share-panel-flash, .share-link-fresh') !== null ||
      (kind === 'catalog' && /[?&](catalog_shared|emailed)=/.test(window.location.search)) ||
      (kind === 'owners' && /[?&](owners_shared|emailed)=/.test(window.location.search));

    card.querySelectorAll('[data-no-toggle]').forEach((el) => {
      el.addEventListener('click', (event) => event.stopPropagation());
      el.addEventListener('pointerdown', (event) => event.stopPropagation());
    });

    if (shouldAnchor) {
      shell.open = true;
      focusShareTarget(card);
    } else if (!forced) {
      try {
        const saved = localStorage.getItem(storageKey);
        if (saved === '1') shell.open = true;
        else if (saved === '0') shell.open = false;
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
})();
