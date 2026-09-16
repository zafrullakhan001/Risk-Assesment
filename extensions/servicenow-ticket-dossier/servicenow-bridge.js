/**
 * Announces a ServiceNow tab to the extension and displays injection failures.
 */

function showExtensionNotice(message, isError) {
  const old = document.getElementById('rr-sn-extension-notice');
  if (old) old.remove();

  const notice = document.createElement('div');
  notice.id = 'rr-sn-extension-notice';
  notice.textContent = message;
  notice.style.cssText = [
    'position:fixed',
    'z-index:2147483647',
    'top:14px',
    'right:14px',
    'max-width:420px',
    'padding:10px 14px',
    'border-radius:9px',
    'font:13px/1.4 system-ui,Segoe UI,sans-serif',
    'box-shadow:0 8px 28px rgba(0,0,0,.3)',
    isError
      ? 'background:#7f1d1d;color:#fee2e2;border:1px solid #ef4444'
      : 'background:#064e3b;color:#d1fae5;border:1px solid #10b981',
  ].join(';');
  document.documentElement.appendChild(notice);
  window.setTimeout(() => notice.remove(), isError ? 15000 : 5000);
}

function safeRuntimeSendMessage(message, callback) {
  try {
    chrome.runtime.sendMessage(message, callback);
    return true;
  } catch (_error) {
    return false;
  }
}

window.addEventListener('message', (event) => {
  if (event.source !== window || event.origin !== window.location.origin) return;
  const data = event.data;
  if (
    !data
    || data.source !== 'riskregister-servicenow-exporter'
    || data.type !== 'RR_SN_EXPORT_COMPLETE'
  ) {
    return;
  }
  safeRuntimeSendMessage({
    type: 'RR_SN_EXPORT_COMPLETE',
    origin: window.location.origin,
    taskNumber: String(data.taskNumber || ''),
  });
});

safeRuntimeSendMessage({
  type: 'RR_SN_TAB_READY',
  origin: window.location.origin,
}, (response) => {
  if (chrome.runtime.lastError) {
    return;
  }
  if (response && response.ok && response.injected) {
    showExtensionNotice('RiskRegister exporter started. Use the Export packet overlay.', false);
    return;
  }
  if (response && !response.waiting && response.error) {
    showExtensionNotice('RiskRegister automatic start failed: ' + response.error, true);
  }
});
