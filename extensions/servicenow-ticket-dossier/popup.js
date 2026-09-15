const statusEl = document.getElementById('status');
const detailsEl = document.getElementById('details');
const kindEl = document.getElementById('kind');
const sourceEl = document.getElementById('source');
const targetEl = document.getElementById('target');
const expiresEl = document.getElementById('expires');
const errorEl = document.getElementById('error');
const clearBtn = document.getElementById('clear');
let clearMessageType = '';

function getPending(type) {
  return new Promise((resolve) => {
    chrome.runtime.sendMessage({ type }, (response) => {
      if (chrome.runtime.lastError || !response || !response.ok) {
        resolve({ available: false, pending: null });
        return;
      }
      resolve({ available: true, pending: response.pending || null });
    });
  });
}

async function loadStatus() {
  const [sharePoint, serviceNow] = await Promise.all([
    getPending('RR_SP_GET_STATUS'),
    getPending('RR_SN_GET_STATUS'),
  ]);
  if (!sharePoint.available && !serviceNow.available) {
    statusEl.textContent = 'Extension service worker is unavailable.';
    return;
  }

  const pending = sharePoint.pending || serviceNow.pending;
  const isSharePoint = !!sharePoint.pending;
    if (!pending) {
      statusEl.textContent = 'No sync is currently prepared.';
      detailsEl.hidden = true;
      clearBtn.hidden = true;
      errorEl.hidden = true;
      return;
    }

  statusEl.textContent = pending.lastStatus || 'Sync prepared.';
  kindEl.textContent = isSharePoint ? 'SharePoint (read-only)' : 'ServiceNow export';
  sourceEl.textContent = isSharePoint ? (pending.sourceKey || '—') : (pending.taskNumber || '—');
  targetEl.textContent = isSharePoint
    ? (pending.sharePointOrigin || '—')
    : (pending.instanceOrigin || '—');
  const expires = Number(pending.expiresAt || 0);
  expiresEl.textContent = expires > 0 ? new Date(expires * 1000).toLocaleString() : '—';
  detailsEl.hidden = false;
  clearBtn.hidden = false;
  clearMessageType = isSharePoint ? 'RR_SP_CLEAR' : 'RR_SN_CLEAR';
  if (pending.lastError) {
    errorEl.textContent = pending.lastError;
    errorEl.hidden = false;
  } else {
    errorEl.hidden = true;
  }
}

clearBtn.addEventListener('click', () => {
  if (!clearMessageType) return;
  chrome.runtime.sendMessage({ type: clearMessageType }, () => loadStatus());
});

loadStatus();
