const statusEl = document.getElementById('status');
const detailsEl = document.getElementById('details');
const taskEl = document.getElementById('task');
const instanceEl = document.getElementById('instance');
const expiresEl = document.getElementById('expires');
const errorEl = document.getElementById('error');
const clearBtn = document.getElementById('clear');

function loadStatus() {
  chrome.runtime.sendMessage({ type: 'RR_SN_GET_STATUS' }, (response) => {
    if (chrome.runtime.lastError || !response || !response.ok) {
      statusEl.textContent = 'Extension service worker is unavailable.';
      return;
    }
    const pending = response.pending;
    if (!pending) {
      statusEl.textContent = 'No export is currently prepared.';
      detailsEl.hidden = true;
      clearBtn.hidden = true;
      errorEl.hidden = true;
      return;
    }

    statusEl.textContent = pending.lastStatus || 'Export prepared.';
    taskEl.textContent = pending.taskNumber || '—';
    instanceEl.textContent = pending.instanceOrigin || '—';
    const expires = Number(pending.expiresAt || 0);
    expiresEl.textContent = expires > 0 ? new Date(expires * 1000).toLocaleString() : '—';
    detailsEl.hidden = false;
    clearBtn.hidden = false;
    if (pending.lastError) {
      errorEl.textContent = pending.lastError;
      errorEl.hidden = false;
    } else {
      errorEl.hidden = true;
    }
  });
}

clearBtn.addEventListener('click', () => {
  chrome.runtime.sendMessage({ type: 'RR_SN_CLEAR' }, () => loadStatus());
});

loadStatus();
