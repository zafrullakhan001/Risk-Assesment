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
  const csrf = root.getAttribute('data-csrf') || '';

  let lastScript = '';
  let lastOpenUrl = '';

  const setStatus = (text, ok) => {
    if (!statusEl) return;
    statusEl.textContent = text;
    statusEl.classList.toggle('is-ok', ok === true);
    statusEl.classList.toggle('is-error', ok === false);
  };

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

      const copied = await copyScript();
      if (lastOpenUrl) {
        window.open(lastOpenUrl, '_blank', 'noopener,noreferrer');
      }

      setStatus(
        (copied
          ? 'Script copied. Paste it into the ServiceNow F12 console, then click Export packet on the overlay.'
          : 'Script ready (copy failed — use the text box). Paste into the ServiceNow F12 console, then click Export packet.') +
          ' Token expires in about 30 minutes.',
        true
      );
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
})();
