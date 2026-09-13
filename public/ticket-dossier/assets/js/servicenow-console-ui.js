/**
 * Ticket Dossier UI: prepare ServiceNow console sync token, copy script, open instance.
 */
(() => {
  const root = document.getElementById('servicenow-console-sync');
  if (!root || !window.ServiceNowConsoleSync) return;

  const prepareBtn = document.getElementById('servicenow-console-prepare');
  const copyBtn = document.getElementById('servicenow-console-copy');
  const openLink = document.getElementById('servicenow-console-open');
  const statusEl = document.getElementById('servicenow-console-status');
  const scriptEl = document.getElementById('servicenow-console-script');
  const instanceInput = document.getElementById('servicenow-console-instance');
  const taskInput = document.getElementById('servicenow-console-task');
  const rememberFolderInput = document.getElementById('servicenow-console-remember-folder');
  const csrf = root.getAttribute('data-csrf') || '';
  const rememberFolderKey = 'ticketDossier.servicenow.rememberFolder';
  const instanceUrlKey = 'ticketDossier.servicenow.instanceUrl';

  let lastScript = '';
  let lastOpenUrl = '';
  let extensionAvailable = false;
  const extensionRequests = new Map();

  const pageSource = 'riskregister-servicenow-page';
  const extensionSource = 'riskregister-servicenow-extension';

  window.addEventListener('message', (event) => {
    if (event.source !== window || event.origin !== window.location.origin) return;
    const data = event.data;
    if (!data || data.source !== extensionSource) return;

    if (data.type === 'RR_SN_EXTENSION_READY') {
      extensionAvailable = true;
      root.classList.add('has-servicenow-extension');
      if (prepareBtn) {
        prepareBtn.textContent = '▶ Prepare + open automatically';
        prepareBtn.title = 'The installed extension will start the exporter in ServiceNow';
      }
      if (statusEl && statusEl.textContent === 'Not prepared yet.') {
        setStatus('Browser extension detected. Automatic start is ready.', true);
      }
      return;
    }

    if (data.type === 'RR_SN_EXTENSION_PREPARE_RESULT') {
      const requestId = String(data.requestId || '');
      const pending = extensionRequests.get(requestId);
      if (pending) {
        extensionRequests.delete(requestId);
        pending(data);
      }
      return;
    }

    if (data.type === 'RR_SN_EXTENSION_INJECTED') {
      setStatus(data.message || 'Exporter started automatically in ServiceNow.', true);
      return;
    }

    if (data.type === 'RR_SN_EXTENSION_ERROR') {
      setStatus(data.message || 'Automatic start failed. Use Copy script again as fallback.', false);
    }
  });

  const prepareExtension = (payload, script) => {
    if (!extensionAvailable) {
      return Promise.resolve({ ok: false, error: 'Extension not detected.' });
    }
    const requestId =
      (window.crypto && typeof window.crypto.randomUUID === 'function')
        ? window.crypto.randomUUID()
        : String(Date.now()) + '-' + Math.random().toString(16).slice(2);

    return new Promise((resolve) => {
      const timer = window.setTimeout(() => {
        extensionRequests.delete(requestId);
        resolve({ ok: false, error: 'Extension did not acknowledge the prepared export.' });
      }, 2500);
      extensionRequests.set(requestId, (result) => {
        window.clearTimeout(timer);
        resolve(result);
      });
      window.postMessage({
        source: pageSource,
        type: 'RR_SN_PREPARE',
        requestId,
        config: {
          script,
          instanceOrigin: payload.instance_origin,
          taskNumber: payload.task_number,
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

  if (rememberFolderInput) {
    try {
      const savedPreference = window.localStorage.getItem(rememberFolderKey);
      rememberFolderInput.checked = savedPreference === null ? true : savedPreference === '1';
    } catch {
      rememberFolderInput.checked = true;
    }
    rememberFolderInput.addEventListener('change', () => {
      try {
        window.localStorage.setItem(rememberFolderKey, rememberFolderInput.checked ? '1' : '0');
      } catch {
        // Export still works when browser storage is unavailable.
      }
    });
  }

  if (instanceInput) {
    try {
      const savedInstanceUrl = window.localStorage.getItem(instanceUrlKey);
      if (savedInstanceUrl) {
        instanceInput.value = savedInstanceUrl;
      }
    } catch {
      // The URL remains editable when browser storage is unavailable.
    }
    instanceInput.addEventListener('change', () => {
      const value = instanceInput.value.trim();
      try {
        if (value) {
          window.localStorage.setItem(instanceUrlKey, value);
        } else {
          window.localStorage.removeItem(instanceUrlKey);
        }
      } catch {
        // Preparing an export does not depend on persistence.
      }
    });
  }

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

  const prepare = async () => {
    const instanceUrl = (instanceInput && instanceInput.value || '').trim();
    const taskNumber = (taskInput && taskInput.value || '').trim();
    if (!instanceUrl) {
      setStatus('Enter your ServiceNow instance URL (e.g. https://yourcompany.service-now.com).', false);
      return;
    }
    if (!/^TASK\d+$/i.test(taskNumber)) {
      setStatus('Task number must look like TASK0123456.', false);
      return;
    }

    if (prepareBtn) prepareBtn.disabled = true;
    setStatus('Preparing console sync…');

    try {
      const body = new FormData();
      body.set('action', 'prepare_browser_sync');
      body.set('csrf_token', csrf);
      body.set('instance_url', instanceUrl);
      body.set('task_number', taskNumber);

      const response = await fetch('browser-sync.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body,
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || 'Prepare failed.');
      }

      if (instanceInput && payload.instance_origin) {
        instanceInput.value = payload.instance_origin;
        try {
          window.localStorage.setItem(instanceUrlKey, payload.instance_origin);
        } catch {
          // Preparing an export does not depend on persistence.
        }
      }
      payload.remember_folder = !!(rememberFolderInput && rememberFolderInput.checked);
      lastScript = window.ServiceNowConsoleSync.buildConsoleScript(payload);
      lastOpenUrl = payload.open_url || payload.instance_origin || '';

      if (scriptEl) {
        scriptEl.value = lastScript;
      }
      if (openLink && lastOpenUrl) {
        openLink.href = lastOpenUrl;
        openLink.hidden = false;
      }
      if (copyBtn) copyBtn.disabled = false;

      const extensionResult = await prepareExtension(payload, lastScript);
      const copied = extensionResult.ok ? false : await copyScript();
      if (lastOpenUrl) {
        window.open(lastOpenUrl, '_blank', 'noopener,noreferrer');
      }

      if (extensionResult.ok) {
        setStatus(
          'Extension armed for ' + payload.task_number
          + '. ServiceNow is opening; the Export packet overlay will start automatically. Token expires in about 30 minutes.',
          true
        );
      } else {
        setStatus(
          (copied
            ? 'Script copied. Paste it into the ServiceNow F12 console, then click Export packet on the overlay.'
            : 'Script ready (copy failed — use the text box). Paste into the ServiceNow F12 console, then click Export packet.')
            + ' Automatic extension start was unavailable'
            + (extensionResult.error ? ': ' + extensionResult.error : '.')
            + ' Token expires in about 30 minutes.',
          true
        );
      }
    } catch (error) {
      setStatus(error && error.message ? error.message : String(error), false);
    } finally {
      if (prepareBtn) prepareBtn.disabled = false;
    }
  };

  if (prepareBtn) {
    prepareBtn.addEventListener('click', (event) => {
      event.preventDefault();
      prepare();
    });
  }

  if (copyBtn) {
    copyBtn.addEventListener('click', async (event) => {
      event.preventDefault();
      const ok = await copyScript();
      setStatus(ok ? 'Script copied again.' : 'Could not copy — select the script text box manually.', ok);
    });
  }

  // Content scripts can load before this page module; ping after listeners exist.
  window.postMessage({
    source: pageSource,
    type: 'RR_SN_EXTENSION_PING',
  }, window.location.origin);
})();
