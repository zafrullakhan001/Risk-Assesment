/**
 * Admin UI for MFA browser sync.
 * One click: prepare token → copy console script → open SharePoint folder.
 */
(() => {
  const root = document.getElementById('sharepoint-mfa-sync');
  if (!root || !window.SharePointMfaSync) return;

  const prepareBtn = document.getElementById('sharepoint-mfa-prepare');
  const openLink = document.getElementById('sharepoint-mfa-open');
  const copyBtn = document.getElementById('sharepoint-mfa-copy');
  const statusEl = document.getElementById('sharepoint-mfa-status');
  const scriptEl = document.getElementById('sharepoint-mfa-script');
  const sourceKeyInput = document.getElementById('sharepoint-mfa-source-key');
  const csrf = root.getAttribute('data-csrf') || '';

  let lastScript = '';

  const setStatus = (text, ok) => {
    if (!statusEl) return;
    statusEl.textContent = text;
    statusEl.classList.toggle('is-ok', ok === true);
    statusEl.classList.toggle('is-error', ok === false);
  };

  const resolveSourceKey = (el) =>
    (el && el.getAttribute('data-source-key')) ||
    root.getAttribute('data-source-key') ||
    (sourceKeyInput && sourceKeyInput.value) ||
    '';

  const copyScript = async () => {
    if (!lastScript) return false;
    try {
      await navigator.clipboard.writeText(lastScript);
      return true;
    } catch {
      if (scriptEl) {
        scriptEl.hidden = false;
        scriptEl.focus();
        scriptEl.select();
      }
      return false;
    }
  };

  const prepareForSource = async (sourceKey, triggerBtn) => {
    const key = String(sourceKey || '').trim();
    if (!key) {
      setStatus('Missing source_key for MFA sync.', false);
      return;
    }

    if (triggerBtn) triggerBtn.disabled = true;
    if (prepareBtn && triggerBtn !== prepareBtn) prepareBtn.disabled = true;
    setStatus(`Preparing console sync for “${key}”…`);

    try {
      const body = new FormData();
      body.set('action', 'prepare_browser_sync');
      body.set('csrf_token', csrf);
      body.set('source_key', key);
      body.set('source', key);

      const response = await fetch('sharepoint.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body,
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || 'Prepare failed.');
      }

      if (openLink && payload.folder_url) {
        openLink.href = payload.folder_url;
      }
      if (sourceKeyInput && payload.source_key) {
        sourceKeyInput.value = payload.source_key;
      }
      root.setAttribute('data-source-key', payload.source_key || key);
      if (prepareBtn) {
        prepareBtn.setAttribute('data-source-key', payload.source_key || key);
      }

      lastScript = window.SharePointMfaSync.buildConsoleScript(payload);
      if (scriptEl) {
        scriptEl.hidden = false;
        scriptEl.value = lastScript;
      }
      if (copyBtn) copyBtn.disabled = false;

      const copied = await copyScript();
      const mins = Math.max(1, Math.round(((payload.expires_at || 0) * 1000 - Date.now()) / 60000));
      const title = payload.title ? ` (${payload.title})` : '';

      if (payload.folder_url) {
        window.open(payload.folder_url, '_blank', 'noopener');
      }

      if (copied) {
        setStatus(
          `✅ Script copied${title}. SharePoint opened — press F12 → Console → Ctrl+V → Enter. Token valid ~${mins} min. Wait for ✅ Sync complete.`,
          true
        );
      } else {
        setStatus(
          `Ready${title}. Clipboard blocked — click “Copy console script”, then paste in SharePoint F12 Console. Token ~${mins} min.`,
          true
        );
      }
    } catch (error) {
      setStatus(error.message || 'Prepare failed.', false);
    } finally {
      if (triggerBtn) triggerBtn.disabled = false;
      if (prepareBtn) prepareBtn.disabled = false;
    }
  };

  prepareBtn?.addEventListener('click', () => {
    prepareForSource(resolveSourceKey(prepareBtn), prepareBtn);
  });

  document.querySelectorAll('.sharepoint-mfa-prepare-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      prepareForSource(resolveSourceKey(btn), btn);
    });
  });

  copyBtn?.addEventListener('click', async () => {
    if (!lastScript) return;
    const copied = await copyScript();
    setStatus(
      copied
        ? 'Console script copied again. Paste into the SharePoint tab’s F12 Console and press Enter.'
        : 'Select the script below, copy it (Ctrl+C), paste into the SharePoint Console.',
      true
    );
  });
})();
