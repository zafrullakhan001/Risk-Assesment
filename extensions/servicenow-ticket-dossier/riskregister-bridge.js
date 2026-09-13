/**
 * Page bridge for the local RiskRegister application.
 *
 * The page cannot call chrome.runtime directly. This content script relays only
 * narrowly-scoped prepare messages to the extension service worker.
 */

const PAGE_SOURCE = 'riskregister-servicenow-page';
const EXTENSION_SOURCE = 'riskregister-servicenow-extension';

function postToPage(type, payload) {
  window.postMessage({
    source: EXTENSION_SOURCE,
    type,
    ...(payload || {}),
  }, window.location.origin);
}

window.addEventListener('message', (event) => {
  if (event.source !== window || event.origin !== window.location.origin) return;
  const data = event.data;
  if (!data || data.source !== PAGE_SOURCE) return;

  if (data.type === 'RR_SN_EXTENSION_PING') {
    postToPage('RR_SN_EXTENSION_READY', { version: chrome.runtime.getManifest().version });
    return;
  }

  if (data.type !== 'RR_SN_PREPARE') return;

  const requestId = String(data.requestId || '');
  chrome.runtime.sendMessage({
    type: 'RR_SN_PREPARE',
    config: data.config,
  }, (response) => {
    if (chrome.runtime.lastError) {
      postToPage('RR_SN_EXTENSION_PREPARE_RESULT', {
        requestId,
        ok: false,
        error: chrome.runtime.lastError.message,
      });
      return;
    }
    postToPage('RR_SN_EXTENSION_PREPARE_RESULT', {
      requestId,
      ...(response || { ok: false, error: 'Extension did not respond.' }),
    });
  });
});

chrome.runtime.onMessage.addListener((message) => {
  const type = String(message && message.type || '');
  if (type === 'RR_SN_EXTENSION_INJECTED' || type === 'RR_SN_EXTENSION_ERROR') {
    postToPage(type, {
      message: String(message.message || ''),
      taskNumber: String(message.taskNumber || ''),
    });
  }
});

postToPage('RR_SN_EXTENSION_READY', { version: chrome.runtime.getManifest().version });
