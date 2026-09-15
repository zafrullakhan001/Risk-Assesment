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
  let extensionAvailable = false;
  const extensionRequests = new Map();
  const pageSource = 'riskregister-sharepoint-page';
  const extensionSource = 'riskregister-sharepoint-extension';

  window.addEventListener('message', (event) => {
    if (event.source !== window || event.origin !== window.location.origin) return;
    const data = event.data;
    if (!data || data.source !== extensionSource) return;

    if (data.type === 'RR_SP_EXTENSION_READY') {
      extensionAvailable = true;
      root.classList.add('has-sharepoint-extension');
      if (prepareBtn) {
        prepareBtn.textContent = '▶ Prepare + open automatically';
        prepareBtn.title = 'The installed extension will start the read-only sync in SharePoint';
      }
      if (statusEl && statusEl.textContent === 'Not prepared yet.') {
        setStatus('Browser extension detected. Automatic SharePoint start is ready.', true);
      }
      return;
    }

    if (data.type === 'RR_SP_EXTENSION_PREPARE_RESULT') {
      const requestId = String(data.requestId || '');
      const pending = extensionRequests.get(requestId);
      if (pending) {
        extensionRequests.delete(requestId);
        pending(data);
      }
      return;
    }

    if (data.type === 'RR_SP_EXTENSION_INJECTED') {
      setStatus(data.message || 'SharePoint is ready. Click Start sync in the toaster.', true);
      return;
    }

    if (data.type === 'RR_SP_EXTENSION_ERROR') {
      setStatus(data.message || 'Automatic start failed. Use the copied script as fallback.', false);
    }
  });

  const prepareExtension = (payload, script) => {
    if (!extensionAvailable) {
      return Promise.resolve({ ok: false, error: 'Extension not detected.' });
    }

    let sharePointOrigin = '';
    try {
      sharePointOrigin = new URL(payload.folder_url).origin;
    } catch {
      return Promise.resolve({ ok: false, error: 'Invalid SharePoint folder URL.' });
    }

    const requestId =
      (window.crypto && typeof window.crypto.randomUUID === 'function')
        ? window.crypto.randomUUID()
        : String(Date.now()) + '-' + Math.random().toString(16).slice(2);

    return new Promise((resolve) => {
      const timer = window.setTimeout(() => {
        extensionRequests.delete(requestId);
        resolve({ ok: false, error: 'Extension did not acknowledge the prepared sync.' });
      }, 2500);
      extensionRequests.set(requestId, (result) => {
        window.clearTimeout(timer);
        resolve(result);
      });
      window.postMessage({
        source: pageSource,
        type: 'RR_SP_PREPARE',
        requestId,
        config: {
          script,
          sharePointOrigin,
          sourceKey: payload.source_key,
          expiresAt: payload.expires_at,
        },
      }, window.location.origin);
    });
  };

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

      const extensionResult = await prepareExtension(payload, lastScript);
      const copied = extensionResult.ok ? false : await copyScript();
      const mins = Math.max(1, Math.round(((payload.expires_at || 0) * 1000 - Date.now()) / 60000));
      const title = payload.title ? ` (${payload.title})` : '';

      if (payload.folder_url) {
        window.open(payload.folder_url, '_blank', 'noopener');
      }

      if (extensionResult.ok) {
        setStatus(
          `Extension armed${title}. SharePoint is opening; click Start sync in the read-only sync toaster. Token valid ~${mins} min.`,
          true
        );
      } else if (copied) {
        setStatus(
          `✅ Script copied${title}. SharePoint opened — press F12 → Console → Ctrl+V → Enter. Token valid ~${mins} min. Automatic start unavailable${extensionResult.error ? `: ${extensionResult.error}` : '.'}`,
          true
        );
      } else {
        setStatus(
          `Ready${title}. Clipboard blocked — click “Copy console script”, then paste in SharePoint F12 Console. Token ~${mins} min. Automatic start unavailable${extensionResult.error ? `: ${extensionResult.error}` : '.'}`,
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

  window.postMessage({
    source: pageSource,
    type: 'RR_SP_EXTENSION_PING',
  }, window.location.origin);
})();
